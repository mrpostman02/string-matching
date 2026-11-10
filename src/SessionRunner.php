<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class SessionRunner
{
    private SessionRepository $repository;

    public function __construct(SessionRepository $repository)
    {
        $this->repository = $repository;
    }

    /** hold the worker lock for the entire session to prevent duplicate measurements */
    public function run(string $id, ?int $limit = null): array
    {
        $directory = $this->repository->path($id);
        $plan = $this->repository->plan($id);
        if (!is_file($directory . '/confirmation.json')) {
            throw new \RuntimeException('Preview and explicitly confirm this session before execution.');
        }
        if ($limit !== null && $limit < 1) {
            throw new \InvalidArgumentException('Checkpoint limit must be positive.');
        }
        $lock = fopen($directory . '/worker.lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('A worker is already running this session.');
        }
        $completed = 0;
        $segmentId = bin2hex(random_bytes(12));
        try {
            $rows = $this->repository->rows($id);
            $completed = count($rows);
            // check all saved slots before resuming so a damaged schedule cannot mix with new measurements
            $expectedOrdinal = 0;
            foreach ($plan['inputs'] as $groupIndex => $input) {
                for ($number = 1; $number <= $input['runs']; ++$number) {
                    $order = ExperimentEngine::order($groupIndex, $number, $plan['algorithms']);
                    foreach ($order as $index => $algorithm) {
                        ++$expectedOrdinal;
                        if (!isset($rows[$expectedOrdinal])) {
                            continue;
                        }
                        $saved = $rows[$expectedOrdinal];
                        if ($saved['group_id'] !== $input['group_id'] || $saved['algorithm_key'] !== $algorithm
                            || $saved['run_number'] !== $number || $saved['round_order'] !== $order
                            || $saved['execution_order'] !== $index + 1
                            || $saved['experiment_id'] !== ResearchExporter::experimentId($id, $input['group_id'], $algorithm)) {
                            throw new \RuntimeException('A committed row disagrees with the immutable execution schedule.');
                        }
                    }
                }
            }
            if ($rows !== [] && max(array_keys($rows)) > $expectedOrdinal) {
                throw new \RuntimeException('Journal contains a measurement outside the execution plan.');
            }
            $source = Provenance::sourceVersion();
            if ($source['sha256'] !== $plan['source_version']['sha256']) {
                throw new \RuntimeException('Source code changed after preview. Preserve this session and create a new plan using the current code.');
            }
            $runtime = Provenance::runtime();
            foreach (glob($directory . '/events/*.json') ?: [] as $path) {
                $event = AtomicFiles::read($path);
                if (($event['type'] ?? '') === 'segment_started' && $event['runtime'] !== $runtime) {
                    throw new \RuntimeException('Execution environment changed since the first segment. Restore its PHP/runtime settings before resuming.');
                }
            }
            $this->repository->event($id, ['type' => 'segment_started', 'segment_id' => $segmentId,
                'runtime' => $runtime, 'source_sha256' => $source['sha256'], 'already_committed' => $completed]);
            $this->repository->status($id, ['state' => 'running', 'completed' => $completed,
                'planned' => $plan['planned_measurements'], 'error' => '', 'segment_id' => $segmentId, 'pid' => getmypid()]);
            $engine = new ExperimentEngine();
            $ordinal = $new = 0;
            foreach ($plan['inputs'] as $groupIndex => $input) {
                $reference = null;
                foreach (range(1, $input['runs']) as $runNumber) {
                    $order = ExperimentEngine::order($groupIndex, $runNumber, $plan['algorithms']);
                    foreach ($order as $orderIndex => $algorithm) {
                        ++$ordinal;
                        if (isset($rows[$ordinal])) {
                            $saved = $rows[$ordinal];
                            if ($saved['group_id'] !== $input['group_id'] || $saved['algorithm_key'] !== $algorithm
                                || $saved['run_number'] !== $runNumber || $saved['round_order'] !== $order
                                || $saved['execution_order'] !== $orderIndex + 1) {
                                throw new \RuntimeException('A committed row disagrees with the immutable execution schedule.');
                            }
                            continue;
                        }
                        if ($reference === null) {
                            $reference = (new ResultValidator())->reference($input['text'], $input['patterns']);
                            foreach ($plan['algorithms'] as $warmAlgorithm) {
                                $engine->warm($input, $warmAlgorithm, $reference);
                            }
                            $this->repository->event($id, ['type' => 'group_warmed', 'segment_id' => $segmentId,
                                'group_id' => $input['group_id'], 'warmups_per_algorithm' => $input['warmups'],
                                'algorithms' => $plan['algorithms']]);
                        }
                        $row = [
                            'session_id' => $id, 'segment_id' => $segmentId,
                            'experiment_id' => ResearchExporter::experimentId($id, $input['group_id'], $algorithm),
                            'group_id' => $input['group_id'], 'algorithm_key' => $algorithm, 'run_number' => $runNumber,
                            'ordinal' => $ordinal, 'execution_order' => $orderIndex + 1, 'round_order' => $order,
                            'executed_at' => gmdate('c'),
                        ] + $engine->measure($input, $algorithm, $reference);
                        $this->repository->commit($id, $row);
                        ++$completed;
                        ++$new;
                        $this->repository->status($id, ['state' => 'running', 'completed' => $completed,
                            'planned' => $plan['planned_measurements'], 'group_id' => $input['group_id'],
                            'algorithm' => $algorithm, 'run_number' => $runNumber, 'error' => '',
                            'segment_id' => $segmentId, 'pid' => getmypid()]);
                        if ($limit !== null && $new >= $limit && $completed < $plan['planned_measurements']) {
                            $this->repository->status($id, ['state' => 'checkpointed', 'completed' => $completed, 'error' => '']);
                            (new ResearchExporter($this->repository))->refresh($id);
                            return $this->repository->status($id);
                        }
                    }
                }
                (new ResearchExporter($this->repository))->refresh($id);
            }
            $allRows = $this->repository->rows($id);
            if (count($allRows) !== $plan['planned_measurements']) {
                throw new \RuntimeException('Journal count disagrees with the execution plan.');
            }
            $failed = count(array_filter($allRows, static fn(array $row): bool => $row['validation_status'] !== 'PASS'));
            $this->repository->status($id, ['state' => $failed ? 'complete_with_failures' : 'complete',
                'completed' => $completed, 'failed_measurements' => $failed, 'error' => '']);
            (new ResearchExporter($this->repository))->refresh($id);
            if ($plan['mode'] === 'batch') {
                $this->repository->markSaved($id);
            }
            return $this->repository->status($id);
        } catch (\Throwable $exception) {
            try {
                $this->repository->event($id, ['type' => 'worker_failed', 'segment_id' => $segmentId,
                    'error' => get_class($exception) . ': ' . $exception->getMessage()]);
                $this->repository->status($id, ['state' => 'failed', 'completed' => $completed, 'error' => $exception->getMessage()]);
                (new ResearchExporter($this->repository))->refresh($id);
            } catch (\Throwable $reportError) {
                error_log('Original worker failure: ' . $exception->getMessage() . '; failure report/export: ' . $reportError->getMessage());
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}