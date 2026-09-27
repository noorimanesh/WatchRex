<?php

namespace App\Support;

/** Server-side SVG chart geometry — no JavaScript chart library needed. */
class Svg
{
    /**
     * @param  list<array{0:int,1:?float,2?:int}>  $points  [timestamp, value, statusCode]
     * @return array{line:string, area:string, max:float, markers:list<array{x:float,y:float,code:int}>, ticks:list<array{y:float,label:string}>, xlabels:list<array{x:float,label:int}>}
     */
    public static function lineChart(array $points, int $width = 800, int $height = 200, ?float $from = null, ?float $to = null): array
    {
        $values = array_filter(array_column($points, 1), fn ($v) => $v !== null);
        $max = $values ? max($values) : 1;
        $max = self::niceMax($max);
        $from ??= $points ? $points[0][0] : time() - 86400;
        $to ??= $points ? end($points)[0] : time();
        $span = max(1, $to - $from);
        $pad = 4;

        $line = '';
        $markers = [];
        $pen = false;
        $first = null;
        $lastX = null;

        foreach ($points as $p) {
            $x = round(($p[0] - $from) / $span * $width, 1);
            if ($p[1] === null) {
                if (($p[2] ?? 1) === 0) {
                    $markers[] = ['x' => $x, 'y' => $height - $pad, 'code' => 0];
                }
                $pen = false;

                continue;
            }
            $y = round($height - $pad - ($p[1] / $max) * ($height - 2 * $pad), 1);
            $line .= ($pen ? 'L' : 'M').$x.' '.$y.' ';
            $first ??= $x;
            $lastX = $x;
            $pen = true;
            if (($p[2] ?? 1) !== 1) {
                $markers[] = ['x' => $x, 'y' => $y, 'code' => $p[2]];
            }
        }

        // Area starts and ends on the baseline for a clean fill (gaps become straight segments).
        $area = $line !== '' ? "M{$first} {$height} L".str_replace('M', 'L', substr(trim($line), 1))." L{$lastX} {$height} Z" : '';

        $ticks = [];
        foreach ([0, 0.5, 1] as $f) {
            $ticks[] = ['y' => round($height - $pad - $f * ($height - 2 * $pad), 1), 'label' => self::label($max * $f)];
        }

        $xlabels = [];
        for ($i = 0; $i <= 4; $i++) {
            $xlabels[] = ['x' => round($i / 4 * $width, 1), 'label' => (int) ($from + $span * $i / 4)];
        }

        return ['line' => trim($line), 'area' => $area, 'max' => $max, 'markers' => $markers, 'ticks' => $ticks, 'xlabels' => $xlabels];
    }

    /** Aggregate raw points into at most $buckets averaged buckets (keeps worst status). */
    public static function bucket(array $points, int $from, int $to, int $buckets = 120): array
    {
        $size = max(1, ($to - $from) / $buckets);
        $acc = [];
        foreach ($points as [$ts, $value, $code]) {
            $i = (int) floor(($ts - $from) / $size);
            $acc[$i] ??= ['sum' => 0, 'n' => 0, 'code' => 1, 'ts' => $from + ($i + 0.5) * $size];
            if ($value !== null) {
                $acc[$i]['sum'] += $value;
                $acc[$i]['n']++;
            }
            if ($code === 0 || ($code === 2 && $acc[$i]['code'] === 1)) {
                $acc[$i]['code'] = $code;
            }
        }
        ksort($acc);

        return array_values(array_map(fn ($b) => [(int) $b['ts'], $b['n'] ? round($b['sum'] / $b['n']) : null, $b['code']], $acc));
    }

    public static function sparkline(array $values, int $width = 120, int $height = 28): string
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        if (count($values) < 2) {
            return '';
        }
        $max = max($values) ?: 1;
        $min = min($values);
        $range = max(1, $max - $min);
        $step = $width / (count($values) - 1);
        $d = '';
        foreach ($values as $i => $v) {
            $d .= ($i ? 'L' : 'M').round($i * $step, 1).' '.round($height - 2 - (($v - $min) / $range) * ($height - 4), 1).' ';
        }

        return trim($d);
    }

    private static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $exp = 10 ** floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($max <= $m * $exp) {
                return $m * $exp;
            }
        }

        return 10 * $exp;
    }

    private static function label(float $v): string
    {
        return $v >= 1000 ? round($v / 1000, 1).'k' : (string) round($v);
    }
}
