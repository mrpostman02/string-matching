<?php
declare(strict_types=1);

namespace StringMatching\Algorithms;

use StringMatching\OperationCounts;

abstract class AbstractMatcher implements Matcher
{
    protected array $patterns = [];
    protected OperationCounts $counts;
    protected bool $prepared = false;

    protected function initialize(array $patterns, bool $characterComparisons): void
    {
        if ($patterns === [] || !array_is_list($patterns)) {
            throw new \InvalidArgumentException('Supply a nonempty list of patterns.');
        }
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                throw new \InvalidArgumentException('Patterns must be nonempty strings.');
            }
        }
        if (count(array_unique($patterns, SORT_STRING)) !== count($patterns)) {
            throw new \InvalidArgumentException('Patterns must be distinct.');
        }
        $this->patterns = $patterns;
        $this->counts = new OperationCounts($characterComparisons);
        $this->prepared = true;
    }

    protected function beginSearch(): void
    {
        if (!$this->prepared) {
            throw new \LogicException('Call prepare() before search().');
        }
        $this->counts->resetSearch();
    }

    public function operations(): OperationCounts
    {
        if (!$this->prepared) {
            throw new \LogicException('Call prepare() first.');
        }
        return $this->counts;
    }
}
