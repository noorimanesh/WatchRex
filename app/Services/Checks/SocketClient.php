<?php

namespace App\Services\Checks;

/**
 * Minimal line-oriented TCP/TLS client used by protocol checkers
 * (SMTP, IMAP, POP3, FTP, Redis, MySQL…). No extensions required.
 */
class SocketClient
{
    /** @var resource|null */
    private $stream = null;

    public float $connectMs = 0;

    public ?float $tlsMs = null;

    public ?array $certificate = null;

    public ?string $tlsProtocol = null;

    public ?string $tlsCipher = null;

    public function __construct(
        private string $host,
        private int $port,
        private int $timeout = 10,
        private bool $tls = false,
        private bool $verify = true,
    ) {}

    public function connect(): static
    {
        $context = stream_context_create(['ssl' => $this->sslOptions()]);
        $scheme = $this->tls ? 'ssl' : 'tcp';
        $address = str_contains($this->host, ':') && ! str_starts_with($this->host, '[') ? "[{$this->host}]" : $this->host;

        $start = microtime(true);
        $stream = @stream_socket_client("{$scheme}://{$address}:{$this->port}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        $this->connectMs = (microtime(true) - $start) * 1000;

        if (! $stream) {
            throw new CheckFailed($this->describeError($errno, $errstr));
        }

        stream_set_timeout($stream, $this->timeout);
        $this->stream = $stream;

        if ($this->tls) {
            $this->captureTls();
        }

        return $this;
    }

    /** Upgrade a plain connection with STARTTLS-style negotiation. */
    public function enableTls(): void
    {
        stream_context_set_option($this->stream, ['ssl' => $this->sslOptions()]);
        $start = microtime(true);
        $ok = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $this->tlsMs = (microtime(true) - $start) * 1000;

        if (! $ok) {
            throw new CheckFailed(__('TLS negotiation failed (certificate invalid or handshake error).'));
        }

        $this->captureTls();
    }

    public function write(string $data): void
    {
        if (@fwrite($this->stream, $data) === false) {
            throw new CheckFailed(__('Connection closed while writing.'));
        }
    }

    public function readLine(): string
    {
        $line = @fgets($this->stream, 8192);

        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new CheckFailed($meta['timed_out'] ?? false ? __('Timed out waiting for server response.') : __('Connection closed by remote host.'));
        }

        return rtrim($line, "\r\n");
    }

    /** Read an SMTP/FTP style multi-line reply ("250-…" continued, "250 …" final). */
    public function readReply(): array
    {
        $lines = [];
        do {
            $line = $this->readLine();
            $lines[] = $line;
        } while (strlen($line) > 3 && $line[3] === '-');

        return ['code' => (int) substr($lines[count($lines) - 1], 0, 3), 'lines' => $lines, 'text' => implode("\n", $lines)];
    }

    public function command(string $command): array
    {
        $this->write($command."\r\n");

        return $this->readReply();
    }

    public function read(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function sslOptions(): array
    {
        return [
            'verify_peer' => $this->verify,
            'verify_peer_name' => $this->verify,
            'allow_self_signed' => ! $this->verify,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ];
    }

    private function captureTls(): void
    {
        $params = stream_context_get_params($this->stream);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $this->certificate = $cert ? CertificateInspector::summarize($cert) : null;

        $meta = stream_get_meta_data($this->stream);
        $this->tlsProtocol = $meta['crypto']['protocol'] ?? null;
        $this->tlsCipher = $meta['crypto']['cipher_name'] ?? null;
    }

    private function describeError(int $errno, string $errstr): string
    {
        $lower = strtolower($errstr);

        return match (true) {
            str_contains($lower, 'refused') => __('Connection refused on port :port (service not listening or blocked by firewall).', ['port' => $this->port]),
            str_contains($lower, 'timed out') => __('Connection timed out after :s s (host unreachable or port filtered).', ['s' => $this->timeout]),
            str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service') => __('DNS resolution failed for :host', ['host' => $this->host]),
            str_contains($lower, 'certificate') || str_contains($lower, 'ssl') => __('TLS error: :e', ['e' => $errstr]),
            default => trim(($errstr ?: 'Connection failed')." ({$errno})"),
        };
    }
}
