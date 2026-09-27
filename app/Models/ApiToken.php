<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'can_write' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: ApiToken, 1: string} */
    public static function issue(User $user, string $name, bool $canWrite = false, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = 'wrx_api_'.Str::random(48);
        $token = $user->apiTokens()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'can_write' => $canWrite,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function findValid(?string $plain): ?self
    {
        if (! $plain) {
            return null;
        }

        $token = static::with('user')->where('token_hash', hash('sha256', $plain))->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast()) || ! $token->user?->is_active) {
            return null;
        }

        return $token;
    }
}
