<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'type', 'config', 'is_default', 'is_active'])]
class NotificationChannel extends Model
{
    public const TYPES = [
        'email' => 'Email',
        'telegram' => 'Telegram',
        'bale' => 'Bale (بله)',
        'discord' => 'Discord',
        'slack' => 'Slack',
        'teams' => 'Microsoft Teams',
        'ntfy' => 'ntfy (Push)',
        'kavenegar' => 'SMS — Kavenegar',
        'whatsapp' => 'WhatsApp (Cloud API)',
        'webhook' => 'Webhook',
    ];

    /** Config fields per channel type: key => [label, is_secret]. */
    public const FIELDS = [
        'email' => ['to' => ['Recipients (comma separated)', false]],
        'telegram' => ['bot_token' => ['Bot token', true], 'chat_id' => ['Chat ID', false], 'api_base' => ['API base URL (optional, for proxies)', false]],
        'bale' => ['bot_token' => ['Bot token', true], 'chat_id' => ['Chat ID', false]],
        'discord' => ['webhook_url' => ['Webhook URL', true]],
        'slack' => ['webhook_url' => ['Webhook URL', true]],
        'teams' => ['webhook_url' => ['Webhook URL', true]],
        'ntfy' => ['url' => ['Topic URL (e.g. https://ntfy.sh/my-topic)', false], 'token' => ['Access token (optional)', true]],
        'kavenegar' => ['api_key' => ['API key', true], 'receptor' => ['Mobile numbers (comma separated)', false], 'sender' => ['Sender line (optional)', false]],
        'whatsapp' => ['phone_number_id' => ['Phone number ID', false], 'access_token' => ['Access token', true], 'to' => ['Recipient number', false]],
        'webhook' => ['url' => ['URL', false], 'secret' => ['HMAC secret (optional)', true]],
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }
}
