<?php
declare(strict_types=1);

namespace StringMatching\Algorithms;

use StringMatching\MatchResult;
use StringMatching\OperationCounts;

interface Matcher
{
    public function name(): string;
    /** @param list<string> $patterns nonempty, distinct byte strings */
    public function prepare(array $patterns): void;
    public function search(string $text): MatchResult;
    public function operations(): OperationCounts;
}
