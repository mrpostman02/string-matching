<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class DatasetLibrary
{
    private string $root;

    /** Keep prepared inputs separate from measured sessions. */
    public function __construct(string $root)
    {
        $this->root = $root;
        foreach (['', '/sources', '/previews', '/saved'] as $suffix) {
            if (!is_dir($root . $suffix) && !mkdir($root . $suffix, 0775, true) && !is_dir($root . $suffix)) {
                throw new \RuntimeException('Cannot create the dataset library.');
            }
        }
    }

    private function lock(): mixed
    {
        $handle = fopen($this->root . '/library.lock', 'c+b');
        if (!$handle || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the dataset library.');
        }
        return $handle;
    }

    public function preserveSource(string $original, string $filename): string
    {
        new NarrativeCorpus($original);
        $hash = hash('sha256', $original);
        $lock = $this->lock();
        try {
            $path = $this->root . '/sources/' . $hash . '.txt';
            if (is_file($path)) {
                if (hash_file('sha256', $path) !== $hash) {
                    throw new \RuntimeException('A preserved source file failed its checksum.');
                }
            } else {
                AtomicFiles::write($path, $original);
                AtomicFiles::json($this->root . '/sources/' . $hash . '.json',
                    ['sha256' => $hash, 'filename' => basename($filename), 'preserved_at' => gmdate('c')]);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $hash;
    }

    public function original(string $id): string
    {
        if ($id === 'builtin') {
            $original = file_get_contents(dirname(__DIR__) . '/datasets/alice_in_wonderland.txt');
            if ($original === false) {
                throw new \RuntimeException('The bundled Alice source cannot be read.');
            }
            $this->preserveSource($original, 'alice_in_wonderland.txt');
            return $original;
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/', $id)) {
            throw new \InvalidArgumentException('Invalid narrative source identifier.');
        }
        $sourcePath = $this->root . '/sources/' . $id . '.txt';
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('The original narrative source has not been preserved in this library.');
        }
        $original = file_get_contents($sourcePath);
        if ($original === false || !hash_equals($id, hash('sha256', $original))) {
            throw new \RuntimeException('The preserved narrative source is missing or changed.');
        }
        return $original;
    }

    public function corpus(string $id): NarrativeCorpus
    {
        return new NarrativeCorpus($this->original($id));
    }

    public function sources(): array
    {
        $sources = [];
        foreach (glob($this->root . '/sources/*.json') ?: [] as $path) {
            $sources[] = AtomicFiles::read($path);
        }
        return $sources;
    }

    public function stage(array $bundle): string
    {
        foreach ($bundle['inputs'] as $input) {
            Workload::validate($input);
            if ($input['text_type'] === 'Narrative') {
                $this->original($input['source_sha256']);
            }
        }
        $id = bin2hex(random_bytes(16));
        AtomicFiles::json($this->root . '/previews/' . $id . '.json', [
            'id' => $id, 'created_at' => gmdate('c'), 'bundle_sha256' => hash('sha256', json_encode($bundle, JSON_THROW_ON_ERROR)),
            'source_version' => Provenance::sourceVersion(), 'bundle' => $bundle,
        ]);
        return $id;
    }

    public function read(string $id, bool $savedOnly = false): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
            throw new \InvalidArgumentException('Invalid prepared dataset identifier.');
        }
        $path = $this->root . '/saved/' . $id . '.json';
        if (!is_file($path) && !$savedOnly) {
            $path = $this->root . '/previews/' . $id . '.json';
        }
        if (!is_file($path)) {
            throw new \InvalidArgumentException('The prepared dataset is not available.');
        }
        $record = AtomicFiles::read($path);
        if (($record['id'] ?? '') !== $id || !hash_equals($record['bundle_sha256'] ?? '',
            hash('sha256', json_encode($record['bundle'], JSON_THROW_ON_ERROR)))) {
            throw new \RuntimeException('The prepared dataset failed its checksum.');
        }
        foreach ($record['bundle']['inputs'] as $input) {
            Workload::validate($input);
        }
        return $record;
    }

    public function save(string $id, string $groupId = '', array $groupIds = []): string
    {
        $record = $this->read($id);
        if ($groupId !== '') {
            if ($record['bundle']['kind'] !== 'single') {
                throw new \InvalidArgumentException('Save a selected group separately to change its group ID.');
            }
            $input = $record['bundle']['inputs'][0];
            $input['group_id'] = $groupId;
            $metadata = json_decode($input['dataset_metadata_json'], true, 64, JSON_THROW_ON_ERROR);
            $metadata['configuration']['group_id'] = $groupId;
            $input['dataset_metadata_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $record['bundle']['inputs'][0] = Workload::validate($input);
            $record['bundle']['recipe']['group_id'] = $groupId;
        }
        if ($groupIds !== []) {
            if ($record['bundle']['kind'] !== 'matrix' || count($groupIds) !== count($record['bundle']['inputs'])) {
                throw new \InvalidArgumentException('Provide exactly one group ID for each matrix row.');
            }
            foreach ($record['bundle']['inputs'] as $index => &$input) {
                if (!is_string($groupIds[$index] ?? null)) {
                    throw new \InvalidArgumentException('Every matrix group ID must be text.');
                }
                $input['group_id'] = $groupIds[$index];
                $metadata = json_decode($input['dataset_metadata_json'], true, 64, JSON_THROW_ON_ERROR);
                $metadata['configuration']['group_id'] = $groupIds[$index];
                $input['dataset_metadata_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $input = Workload::validate($input);
            }
            unset($input);
            if (count(array_unique($groupIds, SORT_STRING)) !== count($groupIds)) {
                throw new \InvalidArgumentException('Matrix group IDs must be unique.');
            }
            $record['bundle']['group_id_overrides'] = array_values($groupIds);
        }
        $hash = hash('sha256', json_encode($record['bundle'], JSON_THROW_ON_ERROR));
        $savedId = substr($hash, 0, 32);
        $record['id'] = $savedId;
        $record['bundle_sha256'] = $hash;
        $lock = $this->lock();
        try {
            $path = $this->root . '/saved/' . $savedId . '.json';
            if (is_file($path)) {
                if ($this->read($savedId, true)['bundle_sha256'] !== $hash) {
                    throw new \RuntimeException('Refusing to overwrite a different saved dataset.');
                }
            } else {
                AtomicFiles::json($path, $record);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $savedId;
    }

    public function saved(): array
    {
        $records = [];
        foreach (glob($this->root . '/saved/*.json') ?: [] as $path) {
            $records[] = $this->read(basename($path, '.json'), true);
        }
        usort($records, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
        return $records;
    }

    public function input(string $id, string $group): array
    {
        foreach ($this->read($id, true)['bundle']['inputs'] as $input) {
            if ($input['group_id'] === $group) {
                return $input;
            }
        }
        throw new \InvalidArgumentException('The selected group is not present in this saved dataset.');
    }

    public function csv(string $id, ?string $group = null): string
    {
        $inputs = $group === null ? $this->read($id, true)['bundle']['inputs'] : [$this->input($id, $group)];
        $header = BatchInput::COLUMNS;
        for ($index = 4; $index <= max(array_column($inputs, 'pattern_count')); ++$index) {
            $header[] = 'Pattern ' . $index;
        }
        $header = array_merge($header, ['Dataset Metadata JSON', 'Input SHA256', 'Patterns SHA256']);
        $handle = fopen('php://temp', 'w+b');
        if (!$handle) {
            throw new \RuntimeException('Cannot create dataset CSV.');
        }
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, ',', '"', '', "\r\n");
            foreach ($inputs as $input) {
                $row = array_combine(BatchInput::COLUMNS, [
                    $input['group_id'], $input['text_type'], $input['text_length'], $input['pattern_lengths'][0],
                    $input['pattern_count'], $input['text'], $input['patterns'][0], $input['patterns'][1] ?? '',
                    $input['patterns'][2] ?? '', $input['runs'], $input['warmups'], $input['name'], $input['hypothesis'],
                    $input['source_reference'], $input['seed'], $input['generation_method'], $input['source_sha256'],
                    $input['source_offset'], $input['normalization'], $input['sample_id'],
                ]);
                for ($index = 4; $index <= max(array_column($inputs, 'pattern_count')); ++$index) {
                    $row['Pattern ' . $index] = $input['patterns'][$index - 1] ?? '';
                }
                $row['Dataset Metadata JSON'] = $input['dataset_metadata_json'];
                $row['Input SHA256'] = hash('sha256', $input['text']);
                $row['Patterns SHA256'] = hash('sha256', json_encode($input['patterns'], JSON_THROW_ON_ERROR));
                // export exact workload bytes because spreadsheet escaping would change the experiment input
                if (fputcsv($handle, array_map(static fn(string $key): mixed => $row[$key], $header), ',', '"', '', "\r\n") === false) {
                    throw new \RuntimeException('Cannot write dataset CSV.');
                }
            }
            rewind($handle);
            return stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    public function manifest(string $id): array
    {
        $record = $this->read($id, true);
        $groups = [];
        foreach ($record['bundle']['inputs'] as $input) {
            $groups[] = ['group_id' => $input['group_id'], 'text_length' => $input['text_length'],
                'pattern_count' => $input['pattern_count'], 'patterns' => $input['patterns'],
                'runs_per_algorithm' => $input['runs'], 'warmups_per_algorithm' => $input['warmups'],
                'metadata' => json_decode($input['dataset_metadata_json'], true, 64, JSON_THROW_ON_ERROR)];
        }
        return ['dataset_id' => $id, 'bundle_sha256' => $record['bundle_sha256'], 'created_at' => $record['created_at'],
            'source_version' => $record['source_version'], 'profile' => $record['bundle']['profile'],
            'recipe' => $record['bundle']['recipe'], 'groups' => $groups,
            'planned_measurements' => array_sum(array_column($record['bundle']['inputs'], 'runs')) * 3,
            'execution_performed' => false];
    }

    public function reproduce(string $id): string
    {
        $bundle = $this->read($id, true)['bundle'];
        $corpus = null;
        foreach ($bundle['inputs'] as $input) {
            if ($input['text_type'] === 'Narrative') {
                $corpus = $this->corpus($input['source_sha256']);
                break;
            }
        }
        $generator = new DatasetGenerator();
        $regenerated = $bundle['kind'] === 'matrix' ? $generator->matrix($bundle['recipe'], $corpus)
            : $generator->single($bundle['recipe'], $corpus);
        if (isset($bundle['group_id_overrides'])) {
            $regenerated['group_id_overrides'] = $bundle['group_id_overrides'];
            foreach ($regenerated['inputs'] as $index => &$input) {
                $input['group_id'] = $bundle['group_id_overrides'][$index];
                $metadata = json_decode($input['dataset_metadata_json'], true, 64, JSON_THROW_ON_ERROR);
                $metadata['configuration']['group_id'] = $input['group_id'];
                $input['dataset_metadata_json'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            unset($input);
        }
        foreach ($regenerated['inputs'] as $index => $input) {
            $expected = $bundle['inputs'][$index];
            if ($input['text'] !== $expected['text'] || $input['patterns'] !== $expected['patterns']) {
                throw new \RuntimeException('Regeneration differs from the saved inputs. The original dataset was preserved.');
            }
        }
        return $this->stage($regenerated);
    }
}