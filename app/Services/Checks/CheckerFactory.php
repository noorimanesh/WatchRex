<?php

namespace App\Services\Checks;

use App\Enums\MonitorType;

class CheckerFactory
{
    public static function for(MonitorType $type): Checker
    {
        return match ($type) {
            MonitorType::Http, MonitorType::Keyword => new Checkers\HttpChecker,
            MonitorType::Api => new Checkers\ApiChecker,
            MonitorType::Ping => new Checkers\PingChecker,
            MonitorType::Tcp => new Checkers\TcpChecker,
            MonitorType::Udp => new Checkers\UdpChecker,
            MonitorType::Dns => new Checkers\DnsChecker,
            MonitorType::Ssl => new Checkers\SslChecker,
            MonitorType::Smtp => new Checkers\SmtpChecker,
            MonitorType::Imap => new Checkers\ImapChecker,
            MonitorType::Pop3 => new Checkers\Pop3Checker,
            MonitorType::Ftp => new Checkers\FtpChecker,
            MonitorType::Ssh => new Checkers\SshChecker,
            MonitorType::Mysql => new Checkers\MysqlChecker,
            MonitorType::Postgres => new Checkers\PostgresChecker,
            MonitorType::Redis => new Checkers\RedisChecker,
            MonitorType::WebSocket => new Checkers\WebSocketChecker,
            MonitorType::Push => new Checkers\PushChecker,
            MonitorType::Server => new Checkers\ServerChecker,
        };
    }
}
