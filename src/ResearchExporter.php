<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class ResearchExporter
{
    private SessionRepository $repository;

    public function __construct(SessionRepository $repository)
    {
        $this->repository = $repository;
    }

    /** protect spreadsheet cells while preserving exact inputs in the accompanying json fields */
    public static function csv(iterable $rows, array $header): string
    {
        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create CSV output.');
        }
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, ',', '"', '', "\r\n");
            foreach ($rows as $row) {
                $values = [];
                foreach ($header as $field) {
                    $value = $row[$field] ?? '';
                    if (is_float($value)) {
                        $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                    } elseif (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    } elseif (is_string($value) && preg_match('/^[\x00-\x20]*[=+@-]/', $value)) {
                        $value = "'" . $value;
                    }
                    $values[] = $value;
                }
                if (fputcsv($handle, $values, ',', '"', '', "\r\n") === false) {
                    throw new \RuntimeException('Cannot write CSV output.');
                }
            }
            rewind($handle);
            $contents = stream_get_contents($handle);
            if ($contents === false) {
                throw new \RuntimeException('Cannot read CSV output.');
            }
            return $contents;
        } finally {
            fclose($handle);
        }
    }

    public static function experimentId(string $sessionId, string $group, string $algorithm): string
    {
        return substr(hash('sha256', $sessionId . "\0" . $group . "\0" . $algorithm), 0, 32);
    }

    public function summaries(array $plan, array $rows): array
    {
        $summaries = [];
        foreach ($plan['inputs'] as $input) {
            foreach ($plan['algorithms'] as $algorithm) {
                $selected = array_values(array_filter($rows, static fn(array $row): bool =>
                    $row['group_id'] === $input['group_id'] && $row['algorithm_key'] === $algorithm));
                $counters = [];
                foreach ($selected as $row) {
                    foreach ($row['metrics'] ?? [] as $key => $value) {
                        if (!in_array($key, ['preprocessing_ns', 'search_ns', 'total_ns', 'preprocessing_us', 'search_us', 'total_us'], true)
                            && $value !== null) {
                            $counters[$key]['sum'] = ($counters[$key]['sum'] ?? 0) + $value;
                            $counters[$key]['n'] = ($counters[$key]['n'] ?? 0) + 1;
                        }
                    }
                }
                $means = array_map(static fn(array $counter): float => $counter['sum'] / $counter['n'], $counters);
                $summaries[] = [
                    'session_id' => $plan['session_id'], 'experiment_id' => self::experimentId($plan['session_id'], $input['group_id'], $algorithm),
                    'group_id' => $input['group_id'], 'experiment_name' => $input['name'], 'hypothesis' => $input['hypothesis'],
                    'algorithm' => ExperimentEngine::ALGORITHMS[$algorithm], 'algorithm_key' => $algorithm,
                    'text_type' => $input['text_type'], 'text_length_characters' => $input['text_length'],
                    'text_length_bytes' => strlen($input['text']), 'pattern_lengths_json' => json_encode($input['pattern_lengths'], JSON_THROW_ON_ERROR),
                    'pattern_count' => count($input['patterns']), 'requested_runs' => $input['runs'], 'warmups' => $input['warmups'],
                    'condition_complete' => count($selected) === $input['runs'],
                    'source_code_sha256' => $plan['source_version']['sha256'],
                    'sample_id' => $input['sample_id'], 'input_fingerprint' => Workload::fingerprint($input),
                    'stddev_method' => 'sample_n_minus_1', 'mean_counters_json' => json_encode($means, JSON_THROW_ON_ERROR),
                ] + Statistics::summarize($selected);
            }
        }
        return $summaries;
    }

    /** rebuild exports from committed measurements without changing the raw records */
    public function refresh(string $id): void
    {
        $plan = $this->repository->plan($id);
        $rows = $this->repository->rows($id);
        $directory = $this->repository->path($id);
        $sources = [];
        foreach ($plan['inputs'] as $input) {
            $sources[] = [
                'session_id' => $id, 'group_id' => $input['group_id'], 'experiment_name' => $input['name'],
                'hypothesis' => $input['hypothesis'], 'text_type' => $input['text_type'],
                'input_text' => $input['text'], 'input_text_json' => json_encode($input['text'], JSON_THROW_ON_ERROR),
                'patterns_json' => json_encode($input['patterns'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'text_length_characters' => $input['text_length'], 'text_length_bytes' => strlen($input['text']),
                'pattern_lengths_characters_json' => json_encode($input['pattern_lengths'], JSON_THROW_ON_ERROR),
                'pattern_lengths_bytes_json' => json_encode(array_map('strlen', $input['patterns']), JSON_THROW_ON_ERROR),
                'pattern_count' => count($input['patterns']), 'source_reference' => $input['source_reference'],
                'seed' => $input['seed'], 'generation_method' => $input['generation_method'],
                'source_sha256' => $input['source_sha256'], 'source_offset' => $input['source_offset'],
                'normalization' => $input['normalization'], 'sample_id' => $input['sample_id'],
                'text_sha256' => hash('sha256', $input['text']),
                'patterns_sha256' => hash('sha256', json_encode($input['patterns'], JSON_THROW_ON_ERROR)),
                'pattern_sha256s_json' => json_encode(array_map(static fn(string $p): string => hash('sha256', $p), $input['patterns']), JSON_THROW_ON_ERROR),
                'input_fingerprint' => Workload::fingerprint($input), 'sampling_design' => $plan['sampling_design'],
                'dataset_metadata_json' => $input['dataset_metadata_json'] ?? '',
            ];
        }
        AtomicFiles::write($directory . '/source-inputs.csv', self::csv($sources, array_keys($sources[0])));
        $counterKeys = array_keys((new OperationCounts(true))->toArray());
        $hypothesesByGroup = array_column($plan['inputs'], 'hypothesis', 'group_id');
        $runHeader = array_merge(['session_id', 'segment_id', 'experiment_id', 'group_id', 'hypothesis', 'algorithm', 'run_number',
            'ordinal', 'executed_at', 'execution_order', 'round_order_json', 'preprocessing_ns', 'search_ns', 'total_ns',
            'preprocessing_us', 'search_us', 'total_us', 'match_count', 'match_positions_json'], $counterKeys,
            ['validation_status', 'error', 'source_input_reference', 'position_unit']);
        $runRows = (static function () use ($rows, $hypothesesByGroup): \Generator {
            foreach ($rows as $row) {
                yield [
                    'session_id' => $row['session_id'], 'segment_id' => $row['segment_id'], 'experiment_id' => $row['experiment_id'],
                    'group_id' => $row['group_id'], 'hypothesis' => $hypothesesByGroup[$row['group_id']],
                    'algorithm' => ExperimentEngine::ALGORITHMS[$row['algorithm_key']],
                    'run_number' => $row['run_number'], 'ordinal' => $row['ordinal'], 'executed_at' => $row['executed_at'],
                    'execution_order' => $row['execution_order'], 'round_order_json' => json_encode($row['round_order'], JSON_THROW_ON_ERROR),
                    'match_positions_json' => $row['positions'] === null ? '' : json_encode($row['positions'], JSON_THROW_ON_ERROR),
                    'validation_status' => $row['validation_status'], 'error' => $row['error'],
                    'source_input_reference' => 'source-inputs.csv#group_id=' . $row['group_id'],
                    'position_unit' => 'zero_based_byte_offset',
                ] + ($row['metrics'] ?? []);
            }
        })();
        AtomicFiles::write($directory . '/runs.csv', self::csv($runRows, $runHeader));
        $summaries = $this->summaries($plan, $rows);
        AtomicFiles::write($directory . '/summary.csv', self::csv($summaries, array_keys($summaries[0])));
        AtomicFiles::json($directory . '/summary.json', $summaries);
        $events = [];
        foreach (glob($directory . '/events/*.json') ?: [] as $path) {
            $events[] = AtomicFiles::read($path);
        }
        usort($events, static fn(array $a, array $b): int => strcmp($a['recorded_at'], $b['recorded_at']));
        $manifest = $plan + [
            'timing_method' => 'hrtime(true), integer nanoseconds; microseconds = ns/1000',
            'timing_boundaries' => 'before prepare(patterns); after prepare; after search(text). Total = preprocessing + search.',
            'timing_includes' => 'prepare validation, algorithm operation counters, match-position construction',
            'timing_excludes' => 'matcher construction, oracle validation, journaling, CSV, statistics, interface',
            'journal_committed_measurements' => count($rows),
            'confirmation' => is_file($directory . '/confirmation.json') ? AtomicFiles::read($directory . '/confirmation.json') : null,
            'exported_at' => gmdate('c'),
            'export_sha256' => [
                'source-inputs.csv' => hash_file('sha256', $directory . '/source-inputs.csv'),
                'runs.csv' => hash_file('sha256', $directory . '/runs.csv'),
                'summary.csv' => hash_file('sha256', $directory . '/summary.csv'),
            ],
            'events' => $events, 'status_snapshot' => $this->repository->status($id),
            'csv_text_policy' => 'UTF-8 BOM, CRLF, formula-safe display cells; input_text_json/patterns_json preserve exact inputs',
            'statistics_policy' => 'All completed timings retained including validation FAIL; ERROR rows have null timings and remain counted separately. No outlier removal.',
            'resume_policy' => 'Committed ordinal records are skipped. Missing slots resume in original schedule. Each resumed group is re-warmed; events and segment IDs record this.',
            'crash_limit' => 'An interrupted measurement without a committed record cannot be recovered; that slot is measured again on resume. Temporary files are retained.',
        ];
        // keep exact inputs in source-inputs.csv and plan.json to avoid duplicating them in the manifest
        unset($manifest['inputs']);
        AtomicFiles::json($directory . '/experiment-manifest.json', $manifest);

    }
}