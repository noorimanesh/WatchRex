<?php

namespace App\Services\Alerting;

use App\Mail\ViewMail;
use App\Models\NotificationChannel;
use App\Services\Checks\TargetGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class ChannelSender
{
    public function send(NotificationChannel $channel, array $p): void
    {
        $c = $channel->config ?? [];
        $this->guard($channel, $c);
        $text = $p['title']."\n".$p['message'].(isset($p['monitor']['target']) ? "\n".$p['monitor']['target'] : '');
        $http = Http::timeout(15)->withUserAgent(config('watchrex.defaults.user_agent'));

        $response = match ($channel->type) {
            'email' => $this->email($c, $p),
            'telegram' => $http->post((rtrim($c['api_base'] ?? '', '/') ?: 'https://api.telegram.org').'/bot'.$c['bot_token'].'/sendMessage', $this->telegramBody($c, $p)),
            'bale' => $http->post('https://tapi.bale.ai/bot'.$c['bot_token'].'/sendMessage', $this->telegramBody($c, $p, false)),
            'discord' => $http->post($c['webhook_url'], ['embeds' => [[
                'title' => $p['title'], 'description' => $p['message'], 'url' => $p['url'] ?? null,
                'color' => hexdec(ltrim($p['color'], '#')), 'timestamp' => $p['time'], 'footer' => ['text' => 'WatchRex · Fabapars'],
            ]]]),
            'slack' => $http->post($c['webhook_url'], ['text' => "*{$p['title']}*\n{$p['message']}".(isset($p['url']) ? "\n<{$p['url']}|".__('Open in WatchRex').'>' : '')]),
            'teams' => $http->post($c['webhook_url'], [
                '@type' => 'MessageCard', '@context' => 'https://schema.org/extensions',
                'themeColor' => ltrim($p['color'], '#'), 'summary' => $p['title'], 'title' => $p['title'], 'text' => $p['message'],
            ]),
            'ntfy' => $http->withHeaders(array_filter([
                'Title' => $this->ascii($p['title']), 'Tags' => $p['event'], 'Click' => $p['url'] ?? null,
                'Priority' => in_array($p['event'], ['down', 'reminder'], true) ? 'high' : 'default',
                'Authorization' => ! empty($c['token']) ? 'Bearer '.$c['token'] : null,
            ]))->withBody($p['message'], 'text/plain')->post($c['url']),
            'kavenegar' => $http->asForm()->post('https://api.kavenegar.com/v1/'.$c['api_key'].'/sms/send.json', array_filter([
                'receptor' => $c['receptor'], 'message' => mb_substr($text, 0, 600), 'sender' => $c['sender'] ?? null,
            ])),
            'whatsapp' => $http->withToken($c['access_token'])->post('https://graph.facebook.com/v20.0/'.$c['phone_number_id'].'/messages', [
                'messaging_product' => 'whatsapp', 'to' => $c['to'], 'type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4000)],
            ]),
            'webhook' => $this->webhook($http, $c, $p),
            default => throw new RuntimeException("Unknown channel type {$channel->type}"),
        };

        if ($response !== null && $response->failed()) {
            throw new RuntimeException("HTTP {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }
    }

    /** Tenants may not point webhooks at internal addresses (SSRF). */
    private function guard(NotificationChannel $channel, array $c): void
    {
        if (! config('watchrex.block_private_targets') || $channel->user?->isAdmin()) {
            return;
        }

        foreach (['url', 'webhook_url', 'api_base'] as $key) {
            if (! empty($c[$key])) {
                $host = parse_url((string) $c[$key], PHP_URL_HOST);
                if (! $host) {
                    throw new RuntimeException("Invalid {$key}");
                }
                TargetGuard::resolve($host, true);
            }
        }
    }

    private function telegramBody(array $c, array $p, bool $html = true): array
    {
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_NOQUOTES);
        $text = $html
            ? '<b>'.$esc($p['title']).'</b>'."\n".$esc($p['message']).(isset($p['monitor']['target']) ? "\n<code>".$esc($p['monitor']['target']).'</code>' : '')
            : $p['title']."\n".$p['message'];

        return array_filter([
            'chat_id' => $c['chat_id'],
            'text' => $text,
            'parse_mode' => $html ? 'HTML' : null,
            'disable_web_page_preview' => true,
        ], fn ($v) => $v !== null);
    }

    private function webhook($http, array $c, array $p)
    {
        $body = json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['X-WatchRex-Event' => $p['event']];
        if (! empty($c['secret'])) {
            $headers['X-WatchRex-Signature'] = 'sha256='.hash_hmac('sha256', $body, $c['secret']);
        }

        return $http->withHeaders($headers)->withBody($body, 'application/json')->post($c['url']);
    }

    private function email(array $c, array $p): null
    {
        $to = array_filter(array_map('trim', explode(',', (string) ($c['to'] ?? ''))));
        if (! $to) {
            throw new RuntimeException('No recipients');
        }

        Mail::to($to)->send(new ViewMail($p['title'], 'emails.alert', ['p' => $p]));

        return null;
    }

    private function ascii(string $s): string
    {
        return trim(preg_replace('/[^\x20-\x7E]/', '', $s)) ?: 'WatchRex';
    }
}
