<?php
declare(strict_types=1);

namespace StringMatching\Algorithms;

use StringMatching\MatchResult;

final class AhoCorasickMatcher extends AbstractMatcher
{
    private array $next = [];
    private array $failure = [];
    private array $outputs = [];
    private array $lengths = [];

    public function name(): string { return 'AhoCorasick'; }

    public function prepare(array $patterns): void
    {
        $this->initialize($patterns, false);
        $this->next = [[]];
        $this->failure = [0];
        $this->outputs = [[]];
        $this->lengths = array_map('strlen', $patterns);
        $this->counts->trieNodesCreated = 1;
        foreach ($patterns as $id => $pattern) {
            $state = 0;
            for ($i = 0, $m = strlen($pattern); $i < $m; ++$i) {
                $byte = $pattern[$i];
                ++$this->counts->preprocessingTransitionChecks;
                if (!isset($this->next[$state][$byte])) {
                    $this->next[$state][$byte] = count($this->next);
                    $this->next[] = [];
                    $this->failure[] = 0;
                    $this->outputs[] = [];
                    ++$this->counts->trieNodesCreated;
                }
                $state = $this->next[$state][$byte];
                ++$this->counts->preprocessingTransitions;
            }
            $this->outputs[$state][] = $id;
        }

        // advance through the queue by index to avoid repeatedly shifting its remaining entries
        $queue = array_values($this->next[0]);
        for ($head = 0; $head < count($queue); ++$head) {
            $state = $queue[$head];
            foreach ($this->next[$state] as $byte => $child) {
                $queue[] = $child;
                $fallback = $this->failure[$state];
                while (true) {
                    ++$this->counts->preprocessingTransitionChecks;
                    if (isset($this->next[$fallback][$byte])) {
                        $fallback = $this->next[$fallback][$byte];
                        ++$this->counts->preprocessingTransitions;
                        break;
                    }
                    if ($fallback === 0) {
                        break;
                    }
                    $fallback = $this->failure[$fallback];
                    ++$this->counts->preprocessingFailureTraversals;
                }
                $this->failure[$child] = $fallback;
                foreach ($this->outputs[$fallback] as $id) {
                    $this->outputs[$child][] = $id;
                    ++$this->counts->outputEntriesCopied;
                }
            }
        }
    }

    public function search(string $text): MatchResult
    {
        $this->beginSearch();
        $positions = array_fill(0, count($this->patterns), []);
        $state = 0;
        for ($i = 0, $n = strlen($text); $i < $n; ++$i) {
            $byte = $text[$i];
            while (true) {
                ++$this->counts->searchTransitionChecks;
                if (isset($this->next[$state][$byte])) {
                    $state = $this->next[$state][$byte];
                    ++$this->counts->searchTransitions;
                    break;
                }
                if ($state === 0) {
                    break;
                }
                $state = $this->failure[$state];
                ++$this->counts->searchFailureTraversals;
            }
            foreach ($this->outputs[$state] as $id) {
                $positions[$id][] = $i - $this->lengths[$id] + 1;
                ++$this->counts->outputReports;
            }
        }
        return new MatchResult($this->patterns, $positions);
    }

    /** inspect the automaton after timing ends so inspection does not affect the measurement */
    public function automaton(): array
    {
        return ['transitions' => $this->next, 'failure_links' => $this->failure, 'outputs' => $this->outputs];
    }
}
