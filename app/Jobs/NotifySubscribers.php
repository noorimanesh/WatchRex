<?php

namespace App\Jobs;

use App\Mail\ViewMail;
use App\Models\Incident;
use App\Models\StatusPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** E-mails verified status-page subscribers about an incident update. */
class NotifySubscribers implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $incidentId, public string $event, public ?string $message = null) {}

    public function handle(): void
    {
        $incident = Incident::with('monitor')->find($this->incidentId);
        if (! $incident?->monitor) {
            return;
        }

        $pages = StatusPage::where('is_public', true)->where('allow_subscribers', true)
            ->whereHas('monitors', fn ($q) => $q->where('monitors.id', $incident->monitor_id))
            ->with(['subscribers' => fn ($q) => $q->whereNotNull('verified_at')])
            ->get();

        foreach ($pages as $page) {
            $service = $page->monitors()->where('monitors.id', $incident->monitor_id)->first();
            $name = $service?->pivot->display_name ?: $incident->monitor->name;

            foreach ($page->subscribers as $subscriber) {
                try {
                    $subject = "[{$page->title}] {$name}: ".match ($this->event) {
                        'down' => __('Service disruption'),
                        'recovered' => __('Resolved'),
                        default => __('Update'),
                    };
                    Mail::to($subscriber->email)->send(new ViewMail($subject, 'emails.subscriber-notice', [
                        'page' => $page, 'subscriber' => $subscriber, 'incident' => $incident,
                        'event' => $this->event, 'service' => $name, 'note' => $this->message,
                    ]));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
