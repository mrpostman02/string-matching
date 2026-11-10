<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class Statistics
{
    /** retain every completed timing and count failed executions separately */
    public static function summarize(array $rows): array
    {
        $mean = $m2 = $preprocessing = $search = 0.0;
        $times = [];
        $pass = $fail = $errors = 0;
        foreach ($rows as $row) {
            match ($row['validation_status']) {
                'PASS' => ++$pass,
                'FAIL' => ++$fail,
                default => ++$errors,
            };
            if ($row['metrics'] === null) {
                continue;
            }
            $value = $row['metrics']['total_ns'];
            $times[] = $value;
            $count = count($times);
            $preprocessing += $row['metrics']['preprocessing_ns'];
            $search += $row['metrics']['search_ns'];
            $delta = $value - $mean;
            $mean += $delta / $count;
            $m2 += $delta * ($value - $mean);
        }
        sort($times, SORT_NUMERIC);
        $n = count($times);
        return [
            'total_measured_runs' => count($rows), 'timed_runs' => $n,
            'mean_preprocessing_us' => $n ? $preprocessing / $n / 1000 : null,
            'mean_search_us' => $n ? $search / $n / 1000 : null,
            'mean_total_us' => $n ? $mean / 1000 : null,
            'median_total_us' => $n ? ($times[intdiv($n - 1, 2)] / 2 + $times[intdiv($n, 2)] / 2) / 1000 : null,
            'stddev_total_us' => $n > 1 ? sqrt(max(0.0, $m2 / ($n - 1))) / 1000 : null,
            'min_total_us' => $n ? $times[0] / 1000 : null,
            'max_total_us' => $n ? $times[$n - 1] / 1000 : null,
            'validation_passes' => $pass, 'validation_failures' => $fail, 'execution_errors' => $errors,
            'validation_status' => $errors ? 'ERROR' : ($fail ? 'FAIL' : ($pass ? 'PASS' : 'PENDING')),
        ];
    }
}