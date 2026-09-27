<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case User = 'user';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::User => __('User'),
            self::Viewer => __('Viewer (read-only)'),
        };
    }
}
