<?php
declare(strict_types=1);

namespace StringMatching;

final class MatchResult
{
    /** @param list<string> $patterns @param list<list<int>> $positions */
    public function __construct(public readonly array $patterns, public readonly array $positions) {}

    public function count(): int
    {
        return array_sum(array_map('count', $this->positions));
    }

    public function toArray(): array
    {
        $matches = [];
        foreach ($this->patterns as $id => $pattern) {
            $matches[] = [
                'pattern_id' => $id, 'pattern' => $pattern,
                'positions' => $this->positions[$id], 'matching_count' => count($this->positions[$id]),
            ];
        }
        return ['match_count' => $this->count(), 'matches' => $matches];
    }
}
