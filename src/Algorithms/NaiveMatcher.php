<?php
declare(strict_types=1);

namespace StringMatching\Algorithms;

use StringMatching\MatchResult;

final class NaiveMatcher extends AbstractMatcher
{
    public function name(): string { return 'Naive'; }

    public function prepare(array $patterns): void
    {
        $this->initialize($patterns, true);
    }

    public function search(string $text): MatchResult
    {
        $this->beginSearch();
        $positions = array_fill(0, count($this->patterns), []);
        $n = strlen($text);
        foreach ($this->patterns as $id => $pattern) {
            $m = strlen($pattern);
            for ($i = 0; $i <= $n - $m; ++$i) {
                for ($j = 0; $j < $m; ++$j) {
                    ++$this->counts->searchComparisons;
                    if ($text[$i + $j] !== $pattern[$j]) {
                        break;
                    }
                }
                if ($j === $m) {
                    $positions[$id][] = $i;
                    ++$this->counts->outputReports;
                }
            }
        }
        return new MatchResult($this->patterns, $positions);
    }
}
