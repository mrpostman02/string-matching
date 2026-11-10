<?php
declare(strict_types=1);

namespace StringMatching;

final class OperationCounts
{
    public int $preprocessingComparisons = 0;
    public int $searchComparisons = 0;
    public int $preprocessingTransitionChecks = 0;
    public int $preprocessingTransitions = 0;
    public int $preprocessingFailureTraversals = 0;
    public int $trieNodesCreated = 0;
    public int $outputEntriesCopied = 0;
    public int $searchTransitionChecks = 0;
    public int $searchTransitions = 0;
    public int $searchFailureTraversals = 0;
    public int $outputReports = 0;

    public function __construct(public readonly bool $hasCharacterComparisons) {}

    public function resetSearch(): void
    {
        $this->searchComparisons = 0;
        $this->searchTransitionChecks = 0;
        $this->searchTransitions = 0;
        $this->searchFailureTraversals = 0;
        $this->outputReports = 0;
    }

    public function toArray(): array
    {
        return [
            'preprocessing_comparisons' => $this->hasCharacterComparisons ? $this->preprocessingComparisons : null,
            'comparison_count' => $this->hasCharacterComparisons ? $this->searchComparisons : null,
            'preprocessing_transition_checks' => $this->preprocessingTransitionChecks,
            'preprocessing_transitions' => $this->preprocessingTransitions,
            'preprocessing_failure_traversals' => $this->preprocessingFailureTraversals,
            'trie_nodes_created' => $this->trieNodesCreated,
            'output_entries_copied' => $this->outputEntriesCopied,
            'search_transition_checks' => $this->searchTransitionChecks,
            'search_transitions' => $this->searchTransitions,
            'search_failure_traversals' => $this->searchFailureTraversals,
            'output_reports' => $this->outputReports,
        ];
    }
}
