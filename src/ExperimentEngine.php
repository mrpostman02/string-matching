<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

use StringMatching\Algorithms\{Matcher, NaiveMatcher, KMPMatcher, AhoCorasickMatcher};

/** both interfaces use this engine so their measurements follow the same path */
final class ExperimentEngine
{
    public const ALGORITHMS = ['naive' => 'Naive', 'kmp' => 'KMP', 'aho-corasick' => 'Aho-Corasick'];

    public static function matcher(string $algorithm): Matcher
    {
        return match ($algorithm) {
            'naive' => new NaiveMatcher(),
            'kmp' => new KMPMatcher(),
            'aho-corasick' => new AhoCorasickMatcher(),
            default => throw new \InvalidArgumentException('Select a supported algorithm.'),
        };
    }

    /** cycle through all six orders so each algorithm occupies every position equally */
    public static function order(int $groupIndex, int $runNumber, array $selected): array
    {
        if (count($selected) !== 3) {
            return $selected;
        }
        $permutations = [[0, 1, 2], [1, 2, 0], [2, 0, 1], [0, 2, 1], [2, 1, 0], [1, 0, 2]];
        return array_map(static fn(int $i): string => $selected[$i],
            $permutations[($groupIndex + $runNumber - 1) % 6]);
    }

    /** validate warm-ups without counting them as measured runs */
    public function warm(array $input, string $algorithm, MatchResult $reference): void
    {
        $validator = new ResultValidator();
        for ($i = 0; $i < $input['warmups']; ++$i) {
            $matcher = self::matcher($algorithm);
            $matcher->prepare($input['patterns']);
            if (!$validator->identical($reference, $matcher->search($input['text']))) {
                throw new \RuntimeException("$algorithm warm-up validation failed for {$input['group_id']}.");
            }
        }
    }

    /** measure the complete pattern list together and validate the result after timing ends */
    public function measure(array $input, string $algorithm, MatchResult $reference): array
    {
        try {
            $measurement = (new MetricsCollector())->measure(self::matcher($algorithm), $input['text'], $input['patterns']);
            return [
                'metrics' => $measurement['metrics'], 'positions' => $measurement['result']->positions,
                'validation_status' => (new ResultValidator())->identical($reference, $measurement['result']) ? 'PASS' : 'FAIL',
                'error' => '',
            ];
        } catch (\Throwable $exception) {
            return ['metrics' => null, 'positions' => null, 'validation_status' => 'ERROR',
                'error' => get_class($exception) . ': ' . $exception->getMessage()];
        }
    }
}