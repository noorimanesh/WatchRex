<?php

namespace App\Enums;

enum MonitorStatus: string
{
    case Pending = 'pending';
    case Up = 'up';
    case Down = 'down';
    case Warning = 'warning';
    case Maintenance = 'maintenance';
    case Paused = 'paused';

    /** Heartbeat integer codes. */
    public const HB_DOWN = 0;

    public const HB_UP = 1;

    public const HB_WARNING = 2;

    public const HB_MAINTENANCE = 3;

    public static function fromHeartbeat(int $code): self
    {
        return match ($code) {
            self::HB_UP => self::Up,
            self::HB_WARNING => self::Warning,
            self::HB_MAINTENANCE => self::Maintenance,
            default => self::Down,
        };
    }

    public function heartbeatCode(): int
    {
        return match ($this) {
            self::Up => self::HB_UP,
            self::Warning => self::HB_WARNING,
            self::Maintenance => self::HB_MAINTENANCE,
            default => self::HB_DOWN,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Up => __('Up'),
            self::Down => __('Down'),
            self::Warning => __('Degraded'),
            self::Maintenance => __('Maintenance'),
            self::Paused => __('Paused'),
        };
    }

    public function isHealthy(): bool
    {
        return in_array($this, [self::Up, self::Maintenance], true);
    }
}
