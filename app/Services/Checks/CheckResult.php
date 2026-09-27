<?php

namespace App\Services\Checks;

use App\Enums\MonitorStatus;

final class CheckResult
{
    /**
     * @param  array<string, mixed>  $details  Structured diagnostics (timings, certificate, banner…)
     * @param  array<string, mixed>  $meta  Values persisted on the monitor (e.g. ssl expiry, ip)
     */
    public function __construct(
        public MonitorStatus $status,
        public ?int $responseMs = null,
        public string $message = '',
        public array $details = [],
        public array $meta = [],
    ) {}

    public static function up(?int $ms = null, string $message = 'OK', array $details = [], array $meta = []): self
    {
        return new self(MonitorStatus::Up, $ms, $message, $details, $meta);
    }

    public static function down(string $message, ?int $ms = null, array $details = [], array $meta = []): self
    {
        return new self(MonitorStatus::Down, $ms, $message, $details, $meta);
    }

    public static function warning(string $message, ?int $ms = null, array $details = [], array $meta = []): self
    {
        return new self(MonitorStatus::Warning, $ms, $message, $details, $meta);
    }

    public function isDown(): bool
    {
        return $this->status === MonitorStatus::Down;
    }

    public function withWarning(string $message): self
    {
        if ($this->status === MonitorStatus::Up) {
            $this->status = MonitorStatus::Warning;
            $this->message = $message;
        }

        return $this;
    }
}
