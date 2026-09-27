<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckResult;
use App\Services\Domain\DnsLookup;

class DnsChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $type = strtoupper((string) $monitor->setting('record_type', 'A'));
        $expected = trim((string) $monitor->setting('expected', ''));

        $start = microtime(true);
        $records = DnsLookup::records($host, $type);
        $ms = $this->elapsed($start);

        if (! $records) {
            return CheckResult::down(__('No :t record found for :h', ['t' => $type, 'h' => $host]), $ms);
        }

        $values = array_column($records, 'value');
        $details = ['records' => $records, 'hash' => sha1(implode('|', $values))];
        $meta = ['dns_hash' => $details['hash'], 'dns_values' => array_slice($values, 0, 20)];

        if ($expected !== '') {
            $matched = false;
            foreach (array_map('trim', explode(',', $expected)) as $needle) {
                foreach ($values as $value) {
                    if (strcasecmp(rtrim($value, '.'), rtrim($needle, '.')) === 0 || stripos($value, $needle) !== false) {
                        $matched = true;
                    }
                }
            }
            if (! $matched) {
                return CheckResult::down(__('DNS :t does not match expected value (got: :v)', ['t' => $type, 'v' => mb_substr(implode(', ', $values), 0, 200)]), $ms, $details, $meta);
            }
        }

        $result = CheckResult::up($ms, $type.': '.mb_substr(implode(', ', $values), 0, 200), $details, $meta);

        $previous = $monitor->metaValue('dns_hash');
        if ($previous && $previous !== $details['hash'] && $monitor->setting('alert_on_change', true)) {
            $result->withWarning(__('DNS records changed: :v', ['v' => mb_substr(implode(', ', $values), 0, 200)]));
        }

        return $result;
    }
}
