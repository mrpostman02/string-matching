<?php
declare(strict_types=1);

namespace StringMatching\Algorithms;

use StringMatching\MatchResult;

final class KMPMatcher extends AbstractMatcher
{
    private array $tables = [];

    public function name(): string { return 'KMP'; }

    public function prepare(array $patterns): void
    {
        $this->initialize($patterns, true);
        $this->tables = [];
        foreach ($patterns as $pattern) {
            $m = strlen($pattern);
            $lps = array_fill(0, $m, 0);
            $length = 0;
            $i = 1;
            while ($i < $m) {
                ++$this->counts->preprocessingComparisons;
                if ($pattern[$i] === $pattern[$length]) {
                    $lps[$i++] = ++$length;
                } elseif ($length > 0) {
                    $length = $lps[$length - 1];
                } else {
                    $lps[$i++] = 0;
                }
            }
            $this->tables[] = $lps;
        }
    }

    public function search(string $text): MatchResult
    {
        $this->beginSearch();
        $positions = array_fill(0, count($this->patterns), []);
        $n = strlen($text);
        foreach ($this->patterns as $id => $pattern) {
            $m = strlen($pattern);
            $lps = $this->tables[$id];
            $i = $j = 0;
            while ($i < $n) {
                ++$this->counts->searchComparisons;
                if ($text[$i] === $pattern[$j]) {
                    ++$i;
                    ++$j;
                    if ($j === $m) {
                        $positions[$id][] = $i - $m;
                        ++$this->counts->outputReports;
                        $j = $lps[$j - 1];
                    }
                } elseif ($j > 0) {
                    $j = $lps[$j - 1];
                } else {
                    ++$i;
                }
            }
        }
        return new MatchResult($this->patterns, $positions);
    }

    public function lpsTables(): array { return $this->tables; }
}
