<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class SessionRepository
{
    private string $root;

    /** Store sessions outside the web document root. */
    public function __construct(string $root)
    {
        $this->root = $root;
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Cannot create the experiment results directory.');
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $id): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new \InvalidArgumentException('Invalid session identifier.');
        }
        return $this->root . '/' . $id;
    }

    public function plan(string $id): array
    {
        return AtomicFiles::read($this->path($id) . '/plan.json');
    }

    public function sessions(): array
    {
        $sessions = [];
        foreach (glob($this->root . '/*/plan.json') ?: [] as $path) {
            $sessions[] = AtomicFiles::read($path);
        }
        usort($sessions, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return $sessions;
    }

    /** Lock storage while reserving inputs to prevent conflicting sessions. */
    public function create(array $inputs, array $algorithms, string $mode, string $inputHash = '', string $inputCsv = ''): array
    {
        if ($inputs === [] || !in_array($mode, ['manual', 'batch'], true) || $algorithms === []
            || !array_is_list($algorithms) || count(array_unique($algorithms)) !== count($algorithms)) {
            throw new \InvalidArgumentException('Invalid experiment plan.');
        }
        foreach ($algorithms as $key) {
            ExperimentEngine::matcher($key);
        }
        $inputs = array_map([Workload::class, 'validate'], $inputs);
        $ids = array_column($inputs, 'group_id');
        if (count(array_unique($ids, SORT_STRING)) !== count($ids)) {
            throw new \InvalidArgumentException('Duplicate group IDs in the experiment plan.');
        }
        if ($mode === 'batch' && $algorithms !== array_keys(ExperimentEngine::ALGORITHMS)) {
            throw new \InvalidArgumentException('Batch plans must compare all three algorithms.');
        }
        $lock = fopen($this->root . '/campaign.lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot reserve experiment inputs.');
        }
        try {
            foreach ($this->sessions() as $existing) {
                foreach ($existing['inputs'] as $saved) {
                    foreach ($inputs as $input) {
                        if ($saved['group_id'] === $input['group_id']
                            && Workload::fingerprint($saved) !== Workload::fingerprint($input)) {
                            throw new \InvalidArgumentException("Group {$input['group_id']} already has different inputs or run settings. Use a new group ID.");
                        }
                    }
                }
            }
            $id = bin2hex(random_bytes(16));
            $directory = $this->path($id);
            if (!mkdir($directory . '/journal', 0775, true) || !mkdir($directory . '/events', 0775)) {
                throw new \RuntimeException('Cannot create a session directory.');
            }
            $plan = [
                'version' => 2, 'session_id' => $id, 'mode' => $mode, 'created_at' => gmdate('c'),
                'inputs' => $inputs, 'algorithms' => $algorithms,
                'planned_measurements' => array_sum(array_column($inputs, 'runs')) * count($algorithms),
                'planned_warmups' => array_sum(array_column($inputs, 'warmups')) * count($algorithms),
                'input_file_sha256' => $inputHash ?: hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR)),
                'input_hash_scope' => $inputHash ? 'uploaded_csv_bytes' : 'validated_input_json',
                'order_policy' => count($algorithms) === 3 ? 'six-permutations-cyclic-group-offset-v1' : 'selected-algorithm-only',
                'sampling_design' => 'repeated timings of fixed inputs; not independent input samples',
                'source_version' => Provenance::sourceVersion(), 'created_runtime' => Provenance::runtime(),
            ];
            if ($inputCsv !== '') {
                AtomicFiles::write($directory . '/uploaded-input.csv', $inputCsv);
            }
            AtomicFiles::write($directory . '/observations.md', "# Research observations\n\n");
            AtomicFiles::json($directory . '/plan.json', $plan);
            $this->status($id, ['state' => 'awaiting_confirmation', 'completed' => 0, 'error' => '']);
            return $plan;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** save confirmation for this plan so the worker can verify approval after the browser closes */
    public function confirm(string $id, string $method): void
    {
        $this->plan($id);
        $path = $this->path($id) . '/confirmation.json';
        if (!is_file($path)) {
            AtomicFiles::json($path, ['confirmed_at' => gmdate('c'), 'method' => $method]);
            $status = $this->status($id);
            if ($status['state'] === 'awaiting_confirmation') {
                $this->status($id, array_replace($status, ['state' => 'confirmed', 'updated_at' => gmdate('c')]));
            }
        }
    }

    public function status(string $id, ?array $value = null): array
    {
        $path = $this->path($id) . '/status.json';
        if ($value !== null) {
            AtomicFiles::json($path, $value + ['updated_at' => gmdate('c')], true);
        }
        return AtomicFiles::read($path);
    }

    /** read measurements from the journal because status and csv files can be rebuilt after an interruption */
    public function rows(string $id): array
    {
        $rows = [];
        $canonicalPositions = [];
        foreach (glob($this->path($id) . '/journal/*.json') ?: [] as $path) {
            $record = AtomicFiles::read($path);
            $checksum = $record['record_sha256'] ?? '';
            unset($record['record_sha256']);
            if (!hash_equals($checksum, hash('sha256', json_encode($record, JSON_THROW_ON_ERROR)))
                || ($record['session_id'] ?? '') !== $id
                || basename($path) !== sprintf('%09d.json', $record['ordinal'] ?? 0)) {
                throw new \RuntimeException('Corrupt journal record: ' . basename($path) . '. Measurements were left unchanged.');
            }
            // reuse equal position arrays to keep large repetition counts within memory limits
            $positionKey = $record['group_id'] . ':' . $record['algorithm_key'];
            if ($record['positions'] !== null) {
                if (isset($canonicalPositions[$positionKey]) && $canonicalPositions[$positionKey] === $record['positions']) {
                    $record['positions'] = $canonicalPositions[$positionKey];
                } else {
                    $canonicalPositions[$positionKey] = $record['positions'];
                }
            }
            $rows[$record['ordinal']] = $record;
        }
        ksort($rows, SORT_NUMERIC);
        return $rows;
    }

    public function commit(string $id, array $row): void
    {
        $path = $this->path($id) . '/journal/' . sprintf('%09d.json', $row['ordinal']);
        if (is_file($path)) {
            throw new \RuntimeException('Refusing to overwrite a committed measurement.');
        }
        $row['record_sha256'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        AtomicFiles::json($path, $row);
    }

    public function event(string $id, array $event): void
    {
        AtomicFiles::json($this->path($id) . '/events/' . bin2hex(random_bytes(12)) . '.json',
            ['recorded_at' => gmdate('c')] + $event);
    }

    public function markSaved(string $id): void
    {
        $status = $this->status($id);
        if (!in_array($status['state'], ['complete', 'complete_with_failures'], true)) {
            throw new \RuntimeException('Finish the experiment before saving results.');
        }
        AtomicFiles::json($this->path($id) . '/saved.json', ['saved_at' => gmdate('c')]);
    }

    /** keep earlier observations when saving revisions so research notes are not lost */
    public function observe(string $id, string $observation): void
    {
        $this->plan($id);
        if (strlen($observation) > 64000 || !preg_match('//u', $observation)) {
            throw new \InvalidArgumentException('Observations must be valid UTF-8 and at most 64 KB.');
        }
        $directory = $this->path($id);
        $lock = fopen($directory . '/observations.lock', 'c+b');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the research observation.');
        }
        try {
            if (is_file($directory . '/observations.md')) {
                AtomicFiles::write($directory . '/events/observation-' . bin2hex(random_bytes(12)) . '.md',
                    file_get_contents($directory . '/observations.md'));
            }
            AtomicFiles::write($directory . '/observations.md', $observation);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}