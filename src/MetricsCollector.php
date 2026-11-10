<?php
declare(strict_types=1);

namespace StringMatching;

use StringMatching\Algorithms\Matcher;

final class MetricsCollector
{
    public function measure(Matcher $matcher, string $text, array $patterns): array
    {
        // time only preparation and search, including counters and match positions
        // keep matcher construction, validation and storage outside these intervals
        $start = hrtime(true);
        $matcher->prepare($patterns);
        $prepared = hrtime(true);
        $result = $matcher->search($text);
        $finished = hrtime(true);
        return [
            'result' => $result,
            'metrics' => [
                'preprocessing_ns' => $prepared - $start,
                'search_ns' => $finished - $prepared,
                'total_ns' => $finished - $start,
                'preprocessing_us' => ($prepared - $start) / 1000,
                'search_us' => ($finished - $prepared) / 1000,
                'total_us' => ($finished - $start) / 1000,
                'match_count' => $result->count(),
            ] + $matcher->operations()->toArray(),
        ];
    }
}
