<?php

namespace App\Services\Alerting;

use App\Jobs\SendNotification;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Collection;

class Notifier
{
    public const EVENTS = [
        'down' => ['🔴', '#ef4444'],
        'warning' => ['🟠', '#f59e0b'],
        'recovered' => ['🟢', '#10b981'],
        'reminder' => ['🔴', '#ef4444'],
        'ssl' => ['🔐', '#f59e0b'],
        'domain' => ['🌐', '#f59e0b'],
        'dns' => ['🧭', '#6366f1'],
        'server' => ['🖥️', '#f59e0b'],
        'test' => ['🦖', '#10b981'],
    ];

    public function monitorEvent(Monitor $monitor, string $event, string $message, ?Incident $incident = null): void
    {
        $channels = $monitor->channels()->where('is_active', true)->get();
        if ($channels->isEmpty()) {
            $channels = $this->defaults($monitor->user_id);
        }

        $this->send($channels, [
            'event' => $event,
            'title' => $this->title($event, $monitor->name),
            'message' => $message,
            'monitor' => ['id' => $monitor->id, 'name' => $monitor->name, 'type' => $monitor->type->value, 'target' => $monitor->displayTarget(), 'status' => $monitor->status->value],
            'incident_id' => $incident?->id,
            'url' => route('monitors.show', $monitor),
        ]);
    }

    /** Generic owner-level alert (domain expiry, DNS change, server thresholds…). */
    public function userEvent(User|int $user, string $event, string $subject, string $message, ?string $url = null): void
    {
        $this->send($this->defaults($user instanceof User ? $user->id : $user), [
            'event' => $event,
            'title' => $this->title($event, $subject),
            'message' => $message,
            'url' => $url,
        ]);
    }

    public function test(NotificationChannel $channel): void
    {
        (new SendNotification($channel->id, [
            'event' => 'test',
            'title' => '🦖 WatchRex test notification',
            'message' => __('If you can read this, the :c channel works.', ['c' => $channel->name]),
            'url' => route('dashboard'),
        ]))->handle();
    }

    private function defaults(int $userId): Collection
    {
        return NotificationChannel::where('user_id', $userId)->where('is_active', true)->where('is_default', true)->get();
    }

    private function send(Collection $channels, array $payload): void
    {
        $payload['time'] = now()->toIso8601String();
        $payload['color'] = self::EVENTS[$payload['event']][1] ?? '#6366f1';

        foreach ($channels as $channel) {
            SendNotification::dispatch($channel->id, $payload)->onQueue(config('watchrex.queues.alerts'));
        }
    }

    private function title(string $event, string $subject): string
    {
        $icon = self::EVENTS[$event][0] ?? '🔔';

        return match ($event) {
            'down' => "{$icon} {$subject} ".__('is DOWN'),
            'warning' => "{$icon} {$subject} ".__('is degraded'),
            'recovered' => "{$icon} {$subject} ".__('recovered'),
            'reminder' => "{$icon} {$subject} ".__('is still DOWN'),
            default => "{$icon} {$subject}",
        };
    }
}
