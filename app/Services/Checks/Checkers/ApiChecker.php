<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\Checker;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;
use App\Services\Checks\HttpProbe;
use App\Services\Checks\TargetGuard;

/**
 * Multi-step synthetic API monitoring.
 * settings.steps = [{name, method, url, headers:{}, body, expect_status, expect:{path:value}, extract:{var:path}, max_ms}]
 * Extracted values can be reused as {{var}} in later steps (e.g. login -> token -> profile).
 */
class ApiChecker implements Checker
{
    public function check(Monitor $monitor): CheckResult
    {
        $steps = $monitor->setting('steps');
        if (is_string($steps)) {
            $steps = json_decode($steps, true);
        }

        if (! is_array($steps) || ! $steps) {
            $steps = [[
                'name' => 'request',
                'method' => $monitor->method,
                'url' => $monitor->target,
                'body' => $monitor->setting('body'),
                'expect_status' => $monitor->setting('expected_status', '200-299'),
                'expect' => $monitor->setting('json_path') ? [$monitor->setting('json_path') => $monitor->setting('json_expected')] : [],
            ]];
        }

        $probe = new HttpProbe($monitor->timeout, (bool) $monitor->setting('verify_ssl', true), true, 5, TargetGuard::enabledFor($monitor));
        $vars = array_filter(['username' => $monitor->credential('username'), 'password' => $monitor->credential('password'), 'token' => $monitor->credential('bearer_token')]);
        $baseHeaders = HttpChecker::headers($monitor);
        $report = [];
        $total = 0;

        foreach (array_values($steps) as $i => $step) {
            $name = $step['name'] ?? 'step '.($i + 1);
            $url = self::interpolate((string) ($step['url'] ?? $monitor->target), $vars);
            $headers = $baseHeaders;
            foreach ((array) ($step['headers'] ?? []) as $k => $v) {
                $headers[$k] = self::interpolate((string) $v, $vars);
            }
            $body = isset($step['body']) ? self::interpolate(is_array($step['body']) ? json_encode($step['body']) : (string) $step['body'], $vars) : null;
            if (is_array($step['body'] ?? null)) {
                $headers['Content-Type'] ??= 'application/json';
            }

            try {
                $r = $probe->request($step['method'] ?? 'GET', $url, $headers, $body);
            } catch (CheckFailed $e) {
                $report[] = ['step' => $name, 'ok' => false, 'error' => $e->getMessage()];

                return CheckResult::down(__('Step ":s" failed: :e', ['s' => $name, 'e' => $e->getMessage()]), $total, ['steps' => $report]);
            }

            $ms = $r['timings']['total_with_redirects'];
            $total += $ms;
            $json = json_decode($r['body'], true);
            $entry = ['step' => $name, 'status' => $r['status'], 'ms' => $ms, 'ok' => true];

            $error = null;
            if (! HttpChecker::statusMatches($r['status'], (string) ($step['expect_status'] ?? '200-299'))) {
                $error = __('HTTP :c', ['c' => $r['status']]);
            }
            foreach ((array) ($step['expect'] ?? []) as $path => $expected) {
                $actual = data_get($json, $path);
                $actual = is_bool($actual) ? ($actual ? 'true' : 'false') : (is_scalar($actual) ? (string) $actual : null);
                if ($error === null && $actual !== (string) $expected) {
                    $error = __(':p = ":a" (expected ":e")', ['p' => $path, 'a' => $actual ?? 'null', 'e' => $expected]);
                }
            }
            if ($error === null && isset($step['max_ms']) && $ms > (int) $step['max_ms']) {
                $error = __('took :ms ms (limit :max ms)', ['ms' => $ms, 'max' => $step['max_ms']]);
            }
            foreach ((array) ($step['extract'] ?? []) as $var => $path) {
                $vars[$var] = (string) data_get($json, $path, '');
            }

            if ($error !== null) {
                $entry['ok'] = false;
                $entry['error'] = $error;
                $report[] = $entry;

                return CheckResult::down(__('Step ":s" failed: :e', ['s' => $name, 'e' => $error]), $total, ['steps' => $report]);
            }

            $report[] = $entry;
        }

        return CheckResult::up($total, __(':n step(s) passed', ['n' => count($report)]), ['steps' => $report]);
    }

    private static function interpolate(string $value, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => $vars[$m[1]] ?? '', $value);
    }
}
