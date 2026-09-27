<?php

namespace App\Services\Checks\Checkers;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Services\Checks\Checker;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;
use App\Services\Checks\Diagnoser;
use App\Services\Checks\HttpProbe;
use App\Services\Checks\TargetGuard;
use App\Services\ContentWatcher;

class HttpChecker implements Checker
{
    public function check(Monitor $monitor): CheckResult
    {
        $probe = new HttpProbe(
            timeout: $monitor->timeout,
            verifySsl: (bool) $monitor->setting('verify_ssl', true),
            followRedirects: (bool) $monitor->setting('follow_redirects', true),
            guard: TargetGuard::enabledFor($monitor),
        );

        try {
            $r = $probe->request(
                $monitor->method ?: 'GET',
                (string) $monitor->target,
                self::headers($monitor),
                $monitor->setting('body'),
                self::basicAuth($monitor),
            );
        } catch (CheckFailed $e) {
            return CheckResult::down($e->getMessage(), null, ['causes' => Diagnoser::http(null, [], $e->getMessage())]);
        }

        $details = [
            'status_code' => $r['status'],
            'timings' => $r['timings'],
            'size' => $r['size'],
            'ip' => $r['ip'],
            'http_version' => $r['http_version'],
            'redirects' => $r['redirects'],
            'final_url' => $r['url'],
            'server' => $r['headers']['server'] ?? null,
            'content_type' => $r['headers']['content-type'] ?? null,
            'certificate' => $r['certificate'],
        ];
        $meta = ['ip' => $r['ip'], 'last_timings' => $r['timings']];
        if ($r['certificate']['valid_to'] ?? null) {
            $meta['ssl'] = $r['certificate'];
        }

        $ms = $r['timings']['total_with_redirects'];

        if ($r['censored']) {
            return CheckResult::down(__('Blocked by national internet filtering (peyvandha redirect detected).'), $ms,
                $details + ['causes' => [__('Blocked by national filtering / ISP.')]], $meta + ['censored' => true]);
        }

        if (! self::statusMatches($r['status'], (string) $monitor->setting('expected_status', '200-399'))) {
            return CheckResult::down(__('Unexpected HTTP status :code', ['code' => $r['status']]), $ms,
                $details + ['causes' => Diagnoser::http($r['status'], $r['timings'])], $meta);
        }

        if ($error = self::assertBody($monitor, $r['body'])) {
            return CheckResult::down($error, $ms, $details, $meta);
        }

        $result = CheckResult::up($ms, "HTTP {$r['status']}", $details + ['causes' => Diagnoser::http(null, $r['timings'])], $meta);

        if ($monitor->setting('detect_changes')) {
            // Consumed (and removed) by the runner on the hub; never persisted as-is.
            $result->details['_content'] = ContentWatcher::normalize($r['body']);
        }

        $sslDays = $r['certificate']['days_left'] ?? null;
        $warnDays = (int) $monitor->setting('ssl_warn_days', config('watchrex.defaults.ssl_warn_days'));
        if ($sslDays !== null && $sslDays <= $warnDays) {
            $result->withWarning(__('SSL certificate expires in :d days', ['d' => $sslDays]));
        }

        return $result;
    }

    public static function headers(Monitor $monitor): array
    {
        $headers = [];
        foreach (preg_split('/\r?\n/', (string) $monitor->setting('headers', '')) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $k = preg_replace('/[^A-Za-z0-9\-]/', '', $k);
                if ($k !== '') {
                    $headers[$k] = trim(str_replace(["\r", "\n"], '', $v));
                }
            }
        }

        if ($token = $monitor->credential('bearer_token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    public static function basicAuth(Monitor $monitor): ?array
    {
        $user = $monitor->credential('username');

        return $user !== null ? [$user, (string) $monitor->credential('password')] : null;
    }

    /** Accepts "200", "200,301", "200-299" or combinations. */
    public static function statusMatches(int $status, string $rule): bool
    {
        $rule = trim($rule) ?: '200-399';
        foreach (explode(',', $rule) as $part) {
            $part = trim($part);
            if (str_contains($part, '-')) {
                [$min, $max] = array_map('intval', explode('-', $part, 2));
                if ($status >= $min && $status <= $max) {
                    return true;
                }
            } elseif ((int) $part === $status) {
                return true;
            }
        }

        return false;
    }

    private static function assertBody(Monitor $monitor, string $body): ?string
    {
        $keyword = (string) $monitor->setting('keyword', '');
        if ($keyword !== '' || $monitor->type === MonitorType::Keyword) {
            $caseSensitive = (bool) $monitor->setting('keyword_case', false);
            $found = $caseSensitive ? str_contains($body, $keyword) : mb_stripos($body, $keyword) !== false;
            $invert = (bool) $monitor->setting('keyword_invert', false);

            if ($invert && $found) {
                return __('Forbidden keyword ":k" found in response.', ['k' => $keyword]);
            }
            if (! $invert && ! $found) {
                return __('Expected keyword ":k" not found in response.', ['k' => $keyword]);
            }
        }

        $path = (string) $monitor->setting('json_path', '');
        if ($path !== '') {
            $json = json_decode($body, true);
            if (! is_array($json)) {
                return __('Response is not valid JSON.');
            }
            $actual = data_get($json, $path);
            $expected = (string) $monitor->setting('json_expected', '');
            $actualString = is_bool($actual) ? ($actual ? 'true' : 'false') : (is_scalar($actual) ? (string) $actual : json_encode($actual));
            if ($expected !== '' && $actualString !== $expected) {
                return __('JSON :p is ":a", expected ":e".', ['p' => $path, 'a' => mb_substr($actualString, 0, 80), 'e' => $expected]);
            }
            if ($expected === '' && $actual === null) {
                return __('JSON path :p not present in response.', ['p' => $path]);
            }
        }

        return null;
    }
}
