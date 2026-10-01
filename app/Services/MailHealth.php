<?php

namespace App\Services;

use App\Enums\MonitorType;
use App\Models\Domain;
use App\Models\Monitor;
use Illuminate\Support\Collection;

/**
 * Turns a mail group (its SMTP/IMAP/POP3 monitors + the domain's DNS posture)
 * into a checklist of components with a status each: up | warning | down | unknown.
 */
class MailHealth
{
    /** @param Collection<int, Monitor> $monitors */
    public static function components(Collection $monitors, ?Domain $domain): array
    {
        $rows = [];
        $mail = $monitors->filter(fn (Monitor $m) => in_array($m->type, [MonitorType::Smtp, MonitorType::Imap, MonitorType::Pop3], true));

        foreach (['smtp' => __('Outgoing mail (SMTP)'), 'imap' => __('Mailbox access (IMAP)'), 'pop3' => __('Mailbox access (POP3)')] as $type => $label) {
            $items = $mail->filter(fn (Monitor $m) => $m->type->value === $type);
            if ($items->isEmpty()) {
                continue;
            }
            $worst = self::worst($items);
            $first = $items->first();
            $rows[] = ['key' => $type, 'label' => $label, 'status' => $worst,
                'detail' => $items->map(fn ($m) => $m->displayTarget().($m->last_response_ms !== null ? ' · '.$m->last_response_ms.'ms' : ''))->implode('، '),
                'message' => $worst !== 'up' ? $items->firstWhere(fn ($m) => $m->status->value === $worst)?->last_message : null];

            if ($type === 'smtp') {
                $auth = $first->metaValue('last_details.auth');
                if ($auth !== null) {
                    $rows[] = ['key' => 'smtp_auth', 'label' => __('SMTP login test'), 'status' => $auth === 'success' ? 'up' : 'down', 'detail' => $auth === 'success' ? __('Successful') : __('Failed')];
                }
                $relay = $first->metaValue('last_details.open_relay');
                if ($relay !== null) {
                    $rows[] = ['key' => 'open_relay', 'label' => __('Open relay'), 'status' => $relay ? 'down' : 'up', 'detail' => $relay ? __('YES — vulnerable') : __('No')];
                }
            }
        }

        // TLS certificates of mail endpoints.
        $certs = $mail->map(fn ($m) => $m->metaValue('ssl.days_left'))->filter(fn ($d) => $d !== null);
        if ($certs->isNotEmpty()) {
            $min = (int) $certs->min();
            $rows[] = ['key' => 'tls', 'label' => __('Mail TLS certificate'), 'status' => $min < 0 ? 'down' : ($min <= 14 ? 'warning' : 'up'),
                'detail' => trans_choice(':count day left|:count days left', $min)];
        }

        // Blacklists (from SMTP monitors or the domain analysis).
        $listed = $mail->flatMap(fn ($m) => (array) $m->metaValue('rbl.listed', []))
            ->merge(collect($domain?->blacklists ?? [])->flatten())->unique()->values();
        $rblKnown = $mail->contains(fn ($m) => $m->metaValue('rbl') !== null) || ! empty($domain?->blacklists);
        if ($rblKnown) {
            $rows[] = ['key' => 'rbl', 'label' => __('Spam blacklists (RBL)'), 'status' => $listed->isEmpty() ? 'up' : 'down',
                'detail' => $listed->isEmpty() ? __('Not listed') : $listed->implode(', ')];
        }

        // DNS based e-mail security.
        $es = $domain?->email_security;
        if ($es) {
            $rows[] = ['key' => 'mx', 'label' => __('MX records'), 'status' => empty($es['mx']) ? 'down' : 'up',
                'detail' => collect($es['mx'] ?? [])->pluck('host')->implode(', ') ?: __('missing')];
            $ptrMissing = collect($es['mx'] ?? [])->where('ptr_ok', false)->pluck('host');
            if (! empty($es['mx'])) {
                $rows[] = ['key' => 'ptr', 'label' => __('Reverse DNS (PTR)'), 'status' => $ptrMissing->isEmpty() ? 'up' : 'warning',
                    'detail' => $ptrMissing->isEmpty() ? __('OK') : __('missing').': '.$ptrMissing->implode(', ')];
            }
            $spf = $es['spf'] ?? null;
            $rows[] = ['key' => 'spf', 'label' => 'SPF', 'status' => ! $spf ? 'down' : (str_contains($spf, '+all') ? 'down' : (str_contains($spf, '?all') ? 'warning' : 'up')), 'detail' => $spf ?: __('missing')];
            $rows[] = ['key' => 'dkim', 'label' => 'DKIM', 'status' => empty($es['dkim_selectors']) ? 'warning' : 'up', 'detail' => implode(', ', $es['dkim_selectors'] ?? []) ?: __('not found')];
            $rows[] = ['key' => 'dmarc', 'label' => 'DMARC', 'status' => empty($es['dmarc']) ? 'down' : (($es['dmarc_policy'] ?? 'none') === 'none' ? 'warning' : 'up'), 'detail' => $es['dmarc'] ?? __('missing')];
            $rows[] = ['key' => 'mta_sts', 'label' => 'MTA-STS / TLS-RPT', 'status' => ! empty($es['mta_sts']) ? 'up' : 'unknown',
                'detail' => (! empty($es['mta_sts']) ? 'MTA-STS ✓' : 'MTA-STS ✗').' · '.(! empty($es['tls_rpt']) ? 'TLS-RPT ✓' : 'TLS-RPT ✗')];
        }

        return $rows;
    }

    /** Overall status of a component list (worst wins; unknown ignored). */
    public static function overall(array $components): string
    {
        $order = ['down' => 3, 'warning' => 2, 'up' => 1];
        $worst = 'unknown';
        foreach ($components as $c) {
            if (($order[$c['status']] ?? 0) > ($order[$worst] ?? 0)) {
                $worst = $c['status'];
            }
        }

        return $worst;
    }

    public static function score(array $components): ?int
    {
        $scored = array_filter($components, fn ($c) => $c['status'] !== 'unknown');
        if (! $scored) {
            return null;
        }
        $points = array_sum(array_map(fn ($c) => ['up' => 1, 'warning' => 0.5, 'down' => 0][$c['status']] ?? 0, $scored));

        return (int) round($points / count($scored) * 100);
    }

    private static function worst(Collection $monitors): string
    {
        $statuses = $monitors->map(fn ($m) => $m->is_active === false ? 'paused' : $m->status->value);

        return match (true) {
            $statuses->contains('down') => 'down',
            $statuses->contains('warning') => 'warning',
            $statuses->contains('up') => 'up',
            default => 'unknown',
        };
    }
}
