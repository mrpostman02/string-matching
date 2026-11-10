<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class Workload
{
    /** preserve the supplied text and patterns because normalization would change the workload */
    public static function validate(array $input): array
    {
        $input += ['name' => '', 'hypothesis' => '', 'source_reference' => '', 'seed' => '',
            'sample_id' => 'fixed-input-1', 'generation_method' => 'researcher-supplied',
            'source_sha256' => '', 'source_offset' => '', 'normalization' => 'none'];
        if (!is_string($input['group_id'] ?? null)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,79}\z/', $input['group_id'])) {
            throw new \InvalidArgumentException('Group ID must contain 1-80 letters, numbers, dots, underscores or hyphens.');
        }
        if (!in_array($input['text_type'] ?? null, ['Manual', 'Random', 'Narrative'], true)) {
            throw new \InvalidArgumentException('Text Type must be Manual, Random or Narrative.');
        }
        if (!is_string($input['text'] ?? null) || $input['text'] === '' || strlen($input['text']) > 2000000) {
            throw new \InvalidArgumentException('Supply nonempty input text of at most 2 MB.');
        }
        $patterns = $input['patterns'] ?? null;
        if (!is_array($patterns) || !array_is_list($patterns) || $patterns === [] || count($patterns) > 100) {
            throw new \InvalidArgumentException('Supply 1-100 distinct patterns in one condition.');
        }
        foreach ($patterns as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                throw new \InvalidArgumentException('Patterns must be nonempty strings; remove empty pattern rows.');
            }
            self::characters($pattern);
        }
        if (count(array_unique($patterns, SORT_STRING)) !== count($patterns)) {
            throw new \InvalidArgumentException('Duplicate patterns are not supported by the existing matchers.');
        }
        foreach (['runs' => [1, 100000], 'warmups' => [0, 10000]] as $key => [$min, $max]) {
            $value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($value === false) {
                throw new \InvalidArgumentException("$key must be a whole number from $min to $max.");
            }
            $input[$key] = $value;
        }
        if (!is_string($input['hypothesis'])) {
            throw new \InvalidArgumentException('Hypothesis must be blank or contain H1-H5 IDs separated by semicolons.');
        }
        $hypotheses = require dirname(__DIR__) . '/config/hypotheses.php';
        $hypothesisIds = $input['hypothesis'] === '' ? [] : array_map('trim', explode(';', $input['hypothesis']));
        if (array_diff($hypothesisIds, array_keys($hypotheses)) !== []
            || count(array_unique($hypothesisIds, SORT_STRING)) !== count($hypothesisIds)) {
            throw new \InvalidArgumentException('Hypothesis must be blank or contain distinct H1-H5 IDs separated by semicolons.');
        }
        foreach (['name', 'source_reference', 'seed', 'sample_id', 'generation_method', 'source_sha256', 'source_offset', 'normalization'] as $key) {
            if (!is_string($input[$key]) || strlen($input[$key]) > 4000) {
                throw new \InvalidArgumentException("Invalid $key metadata.");
            }
        }
        if ($input['text_type'] === 'Random' && trim($input['seed']) === '') {
            throw new \InvalidArgumentException('Random inputs require a generation seed.');
        }
        if ($input['text_type'] === 'Narrative' && trim($input['source_reference']) === '') {
            throw new \InvalidArgumentException('Narrative inputs require a source reference.');
        }
        $textHash = hash('sha256', $input['text']);
        $patternsHash = hash('sha256', json_encode($patterns, JSON_THROW_ON_ERROR));
        foreach (['text_sha256' => $textHash, 'patterns_sha256' => $patternsHash] as $field => $expectedHash) {
            if (isset($input[$field]) && (!is_string($input[$field]) || !hash_equals($expectedHash, $input[$field]))) {
                throw new \InvalidArgumentException('The declared input hash differs from the exact text or patterns.');
            }
        }
        if (isset($input['dataset_metadata_json']) && $input['dataset_metadata_json'] !== '') {
            if (!is_string($input['dataset_metadata_json']) || strlen($input['dataset_metadata_json']) > 262144) {
                throw new \InvalidArgumentException('Invalid dataset provenance metadata.');
            }
            try {
                $metadata = json_decode($input['dataset_metadata_json'], true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('Dataset provenance metadata is not valid JSON.', 0, $exception);
            }
            if (!is_array($metadata) || ($metadata['text_sha256'] ?? null) !== $textHash
                || ($metadata['patterns_sha256'] ?? null) !== $patternsHash) {
                throw new \InvalidArgumentException('Dataset provenance hashes differ from the supplied input.');
            }
        }
        $input['text_length'] = self::characters($input['text']);
        $input['pattern_lengths'] = array_map([self::class, 'characters'], $patterns);
        $input['pattern_count'] = count($patterns);
        return $input;
    }

    /** count characters for workload dimensions while the matchers continue to report byte offsets */
    public static function characters(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new \InvalidArgumentException('Text and patterns must be valid UTF-8.');
        }
        return $length;
    }

    /** include source inputs and repetition settings so incompatible conditions cannot share a group */
    public static function fingerprint(array $input): string
    {
        $identity = [$input['text'], $input['patterns'], $input['text_type'],
            $input['runs'], $input['warmups'], $input['source_reference'], $input['seed'], $input['sample_id'],
            $input['generation_method'], $input['source_sha256'], $input['source_offset'], $input['normalization']];
        if (!empty($input['dataset_metadata_json'])) {
            $identity[] = $input['dataset_metadata_json'];
        }
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}