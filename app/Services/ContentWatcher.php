<?php

namespace App\Services;

use App\Jobs\CaptureScreenshot;
use App\Models\ContentSnapshot;
use App\Models\Monitor;
use App\Services\Alerting\Notifier;
use App\Services\Checks\CheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Detects website defacement / unexpected content changes.
 *  - text: normalised visible text, compared line-by-line on every check (cheap)
 *  - visual: optional headless-Chrome screenshot, pixel-compared every N hours
 */
class ContentWatcher
{
    private const KEEP_TEXT = 10;

    /** Extract comparable visible text from HTML. */
    public static function normalize(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article|/header|/footer)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_filter(array_map(fn ($l) => trim(preg_replace('/\s+/u', ' ', $l) ?? $l), explode("\n", $text)), fn ($l) => $l !== '');

        return mb_substr(implode("\n", $lines), 0, 200000);
    }

    public function compare(Monitor $monitor, string $text, CheckResult $result): void
    {
        if ($pattern = (string) $monitor->setting('ignore_pattern', '')) {
            $text = @preg_replace('~'.str_replace('~', '\~', $pattern).'~u', '', $text) ?? $text;
        }

        $hash = hash('sha256', $text);
        $last = $monitor->snapshots()->where('kind', 'text')->latest('id')->first();

        if (! $last) {
            $monitor->snapshots()->create(['kind' => 'text', 'hash' => $hash, 'content' => $text, 'change_percent' => 0]);

            return;
        }

        if ($last->hash === $hash) {
            return;
        }

        $old = explode("\n", (string) $last->content);
        $new = explode("\n", $text);
        $removed = array_values(array_diff($old, $new));
        $added = array_values(array_diff($new, $old));
        $percent = round((count($added) + count($removed)) / max(1, max(count($old), count($new))) * 100, 1);
        $percent = min(100, $percent);

        $diff = [
            'added' => array_map(fn ($l) => mb_substr($l, 0, 200), array_slice($added, 0, 8)),
            'removed' => array_map(fn ($l) => mb_substr($l, 0, 200), array_slice($removed, 0, 8)),
            'added_count' => count($added),
            'removed_count' => count($removed),
        ];

        $monitor->snapshots()->create(['kind' => 'text', 'hash' => $hash, 'content' => $text, 'change_percent' => $percent, 'diff' => $diff]);
        $this->prune($monitor, 'text', self::KEEP_TEXT);

        $threshold = (float) $monitor->setting('change_threshold', 5);
        if ($percent >= $threshold) {
            $result->withWarning(__('Page content changed by :p% (:a lines added, :r removed).', ['p' => $percent, 'a' => count($added), 'r' => count($removed)]));
            $result->details['changes'] = $diff + ['percent' => $percent];
        }
    }

    public static function screenshotsAllowed(Monitor $monitor): bool
    {
        return (bool) config('watchrex.screenshots.chrome')
            && ($monitor->user?->isAdmin() || config('watchrex.screenshots.tenants'));
    }

    public function scheduleScreenshot(Monitor $monitor): void
    {
        if (! self::screenshotsAllowed($monitor)) {
            return;
        }

        $hours = max(1, (int) $monitor->setting('visual_hours', 6));
        $last = (int) $monitor->metaValue('visual.at', 0);
        if ($last > time() - $hours * 3600) {
            return;
        }

        CaptureScreenshot::dispatch($monitor->id)->onQueue(config('watchrex.queues.domains'));
    }

    /** Capture a screenshot and compare it with the previous one. */
    public function capture(Monitor $monitor): ?ContentSnapshot
    {
        if (! self::screenshotsAllowed($monitor) || ! $monitor->type->usesUrl()) {
            return null;
        }

        $cfg = config('watchrex.screenshots');
        $dir = "snapshots/{$monitor->id}";
        Storage::disk('local')->makeDirectory($dir);
        $relative = $dir.'/'.now()->format('Ymd-His').'.png';
        $path = Storage::disk('local')->path($relative);

        $args = [$cfg['chrome'], '--headless=new', '--disable-gpu', '--hide-scrollbars', '--mute-audio', '--no-first-run',
            '--window-size='.$cfg['width'].','.$cfg['height'], '--virtual-time-budget=8000',
            '--user-agent='.config('watchrex.defaults.user_agent'), '--screenshot='.$path, (string) $monitor->target];
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            array_splice($args, 1, 0, ['--no-sandbox']);
        }

        $run = Process::timeout(90)->run($args);
        if (! is_file($path)) {
            $this->saveVisualMeta($monitor, ['at' => time(), 'error' => mb_substr($run->errorOutput() ?: 'Screenshot failed', 0, 200)]);

            return null;
        }

        $previous = $monitor->snapshots()->where('kind', 'visual')->latest('id')->first();
        $percent = $previous && $previous->path && Storage::disk('local')->exists($previous->path)
            ? self::pixelDiff(Storage::disk('local')->path($previous->path), $path)
            : 0.0;

        $snapshot = $monitor->snapshots()->create([
            'kind' => 'visual', 'hash' => hash_file('sha256', $path), 'path' => $relative, 'change_percent' => $percent,
        ]);
        $this->prune($monitor, 'visual', (int) $cfg['keep']);

        $this->saveVisualMeta($monitor, ['at' => time(), 'percent' => $percent, 'snapshot' => $snapshot->id]);

        $threshold = (float) $monitor->setting('change_threshold', 5);
        if ($previous && $percent >= $threshold) {
            app()->setLocale($monitor->user->locale ?? config('app.locale'));
            app(Notifier::class)->monitorEvent($monitor, 'warning', __('Visual change detected: :p% of the page looks different.', ['p' => $percent]));
        }

        return $snapshot;
    }

    /** Percentage of pixels that differ noticeably between two screenshots (compared at 160×105). */
    public static function pixelDiff(string $a, string $b): float
    {
        try {
            $imgA = @imagecreatefrompng($a);
            $imgB = @imagecreatefrompng($b);
            if (! $imgA || ! $imgB) {
                return 100.0;
            }

            [$w, $h] = [160, 105];
            $sa = imagecreatetruecolor($w, $h);
            $sb = imagecreatetruecolor($w, $h);
            imagecopyresampled($sa, $imgA, 0, 0, 0, 0, $w, $h, imagesx($imgA), imagesy($imgA));
            imagecopyresampled($sb, $imgB, 0, 0, 0, 0, $w, $h, imagesx($imgB), imagesy($imgB));

            $changed = 0;
            for ($x = 0; $x < $w; $x++) {
                for ($y = 0; $y < $h; $y++) {
                    $ca = imagecolorat($sa, $x, $y);
                    $cb = imagecolorat($sb, $x, $y);
                    $la = 0.299 * (($ca >> 16) & 0xFF) + 0.587 * (($ca >> 8) & 0xFF) + 0.114 * ($ca & 0xFF);
                    $lb = 0.299 * (($cb >> 16) & 0xFF) + 0.587 * (($cb >> 8) & 0xFF) + 0.114 * ($cb & 0xFF);
                    if (abs($la - $lb) > 32) {
                        $changed++;
                    }
                }
            }

            return round($changed / ($w * $h) * 100, 1);
        } catch (Throwable) {
            return 100.0;
        }
    }

    /** Uses the runner's per-monitor lock so concurrent check results are not overwritten. */
    private function saveVisualMeta(Monitor $monitor, array $visual): void
    {
        Cache::lock("monitor-process:{$monitor->id}", 30)->block(10, function () use ($monitor, $visual) {
            $monitor->refresh();
            $monitor->forceFill(['meta' => array_merge($monitor->meta ?? [], ['visual' => $visual])])->save();
        });
    }

    private function prune(Monitor $monitor, string $kind, int $keep): void
    {
        $old = $monitor->snapshots()->where('kind', $kind)->orderByDesc('id')->skip($keep)->take(100)->get();
        foreach ($old as $snap) {
            if ($snap->path) {
                Storage::disk('local')->delete($snap->path);
            }
            $snap->delete();
        }
    }
}
