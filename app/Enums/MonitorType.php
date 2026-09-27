<?php

namespace App\Enums;

enum MonitorType: string
{
    case Http = 'http';
    case Keyword = 'keyword';
    case Api = 'api';
    case Ping = 'ping';
    case Tcp = 'tcp';
    case Udp = 'udp';
    case Dns = 'dns';
    case Ssl = 'ssl';
    case Smtp = 'smtp';
    case Imap = 'imap';
    case Pop3 = 'pop3';
    case Ftp = 'ftp';
    case Ssh = 'ssh';
    case Mysql = 'mysql';
    case Postgres = 'postgres';
    case Redis = 'redis';
    case WebSocket = 'websocket';
    case Push = 'push';
    case Server = 'server';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP(S)',
            self::Keyword => 'HTTP Keyword',
            self::Api => 'API / Synthetic',
            self::Ping => 'Ping (ICMP)',
            self::Tcp => 'TCP Port',
            self::Udp => 'UDP Port',
            self::Dns => 'DNS',
            self::Ssl => 'SSL Certificate',
            self::Smtp => 'SMTP',
            self::Imap => 'IMAP',
            self::Pop3 => 'POP3',
            self::Ftp => 'FTP',
            self::Ssh => 'SSH / SFTP',
            self::Mysql => 'MySQL / MariaDB',
            self::Postgres => 'PostgreSQL',
            self::Redis => 'Redis',
            self::WebSocket => 'WebSocket',
            self::Push => 'Push / Cron Job',
            self::Server => 'Server Agent',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::Http, self::Keyword, self::Api, self::WebSocket => 'web',
            self::Smtp, self::Imap, self::Pop3 => 'mail',
            self::Mysql, self::Postgres, self::Redis => 'database',
            self::Push, self::Server => 'passive',
            default => 'network',
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::Smtp => 587,
            self::Imap => 993,
            self::Pop3 => 995,
            self::Ftp => 21,
            self::Ssh => 22,
            self::Mysql => 3306,
            self::Postgres => 5432,
            self::Redis => 6379,
            self::Ssl => 443,
            self::Dns, self::Udp => 53,
            default => null,
        };
    }

    public function usesUrl(): bool
    {
        return in_array($this, [self::Http, self::Keyword, self::Api, self::WebSocket], true);
    }

    public function usesPort(): bool
    {
        return ! $this->usesUrl() && ! in_array($this, [self::Ping, self::Dns, self::Push, self::Server], true);
    }

    public function supportsCredentials(): bool
    {
        return in_array($this, [self::Smtp, self::Imap, self::Pop3, self::Ftp, self::Mysql, self::Postgres, self::Redis, self::Http, self::Keyword, self::Api], true);
    }

    public function isPassive(): bool
    {
        return in_array($this, [self::Push, self::Server], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
