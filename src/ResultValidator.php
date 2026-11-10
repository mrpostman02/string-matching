<?php
declare(strict_types=1);

namespace StringMatching;

final class ResultValidator
{
    /** use an independent reference that includes overlapping matches outside the timed intervals */
    public function reference(string $text, array $patterns): MatchResult
    {
        $positions = [];
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                throw new \InvalidArgumentException('Reference patterns must be nonempty strings.');
            }
            $found = [];
            $offset = 0;
            while ($offset <= strlen($text) && ($position = strpos($text, $pattern, $offset)) !== false) {
                $found[] = $position;
                $offset = $position + 1;
            }
            $positions[] = $found;
        }
        return new MatchResult($patterns, $positions);
    }

    public function identical(MatchResult $expected, MatchResult $actual): bool
    {
        return $expected->patterns === $actual->patterns && $expected->positions === $actual->positions;
    }
}
