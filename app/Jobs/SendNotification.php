<?php

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Services\Alerting\ChannelSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public int $channelId, public array $payload) {}

    public function handle(?ChannelSender $sender = null): void
    {
        $channel = NotificationChannel::find($this->channelId);
        if (! $channel || ! $channel->is_active) {
            return;
        }

        try {
            ($sender ?? app(ChannelSender::class))->send($channel, $this->payload);
            $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $e) {
            $channel->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
            throw $e;
        }
    }
}
