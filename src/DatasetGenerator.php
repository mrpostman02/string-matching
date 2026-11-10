<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

use Random\Engine\Mt19937;
use Random\Randomizer;

final class DatasetGenerator
{
    public const PROFILE = 'string-matching-ascii-nested-v1';
    public const NORMALIZATION = 'ascii-lower-letters-spaces-v1';
    public const NORMALIZATION_RULE = 'lowercase ASCII letters; replace all non-ASCII-letter/non-whitespace characters with spaces; collapse whitespace; trim corpus boundaries before selecting exact-length prefixes';
    public const ALPHABETS = [
        'lowercase' => 'abcdefghijklmnopqrstuvwxyz',
        'lowercase-spaces' => 'abcdefghijklmnopqrstuvwxyz ',
        'mixed' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
    ];

    public static function defaults(): array
    {
        return ['text_type' => 'Random', 'text_length' => '500', 'pattern_length' => '8',
            'pattern_count' => '3', 'seed' => '755', 'pattern_seed' => '755', 'runs' => '30', 'warmups' => '2',
            'group_id' => '', 'alphabet' => 'lowercase-spaces', 'custom_alphabet' => '', 'normalization' => 'ascii',
            'pattern_selection' => 'derived', 'custom_patterns' => '', 'narrative_selection' => 'seeded',
            'chapter' => 'I', 'start_offset' => '0', 'justification' => '', 'source_id' => 'builtin'];
    }

    public static function integer(mixed $value, string $label, int $min, int $max): int
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $value)
            || ($number = filter_var($value, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => $min, 'max_range' => $max]])) === false) {
            throw new \InvalidArgumentException("$label must be an integer from $min to $max.");
        }
        return $number;
    }

    public static function settings(array $raw): array
    {
        if (PHP_VERSION_ID < 80200 || PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('Dataset generation requires PHP 8.2 or newer on a 64-bit runtime.');
        }
        $settings = array_intersect_key($raw + self::defaults(), self::defaults());
        foreach ($settings as $key => $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new \InvalidArgumentException('Invalid generation setting: ' . $key);
            }
        }
        foreach (['text_length' => [1, 100000], 'pattern_length' => [1, 1000], 'pattern_count' => [1, 100],
            'seed' => [0, 4294967295], 'pattern_seed' => [0, 4294967295], 'runs' => [1, 100000],
            'warmups' => [0, 10000], 'start_offset' => [0, PHP_INT_MAX]] as $key => [$min, $max]) {
            $settings[$key] = self::integer($settings[$key], $key, $min, $max);
        }
        foreach (['text_type' => ['Random', 'Narrative'], 'alphabet' => ['lowercase', 'lowercase-spaces', 'mixed', 'custom'],
            'normalization' => ['ascii', 'none'], 'pattern_selection' => ['derived', 'custom'],
            'narrative_selection' => ['seeded', 'chapter', 'offset']] as $key => $allowed) {
            if (!in_array($settings[$key], $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported generation setting: ' . $key);
            }
        }
        if ($settings['group_id'] !== '' && !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,79}\z/', $settings['group_id'])) {
            throw new \InvalidArgumentException('Use a group ID of at most 80 letters, digits, dots, underscores or hyphens.');
        }
        if ($settings['pattern_length'] > $settings['text_length']) {
            throw new \InvalidArgumentException('Pattern Length cannot exceed Text Length.');
        }
        if (strlen($settings['justification']) > 2000 || strlen($settings['custom_patterns']) > 200000
            || strlen($settings['custom_alphabet']) > 1024) {
            throw new \InvalidArgumentException('Generation settings exceed the supported size.');
        }
        return $settings;
    }

    public static function normalize(string $text): string
    {
        Workload::characters($text);
        return trim(preg_replace('/[ \t\r\n\f\v]+/', ' ', preg_replace('/[^a-z \t\r\n\f\v]+/', ' ', strtolower($text))));
    }

    public static function characters(string $text): array
    {
        Workload::characters($text);
        return preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    public static function randomizer(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937($seed, MT_RAND_MT19937));
    }

    public function random(array $settings): array
    {
        $settings = self::settings($settings);
        $alphabet = $settings['alphabet'] === 'custom' ? $settings['custom_alphabet'] : self::ALPHABETS[$settings['alphabet']];
        if (str_contains($alphabet, "\0")) {
            throw new \InvalidArgumentException('The alphabet cannot contain NUL characters.');
        }
        $characters = self::characters($alphabet);
        if ($characters === [] || count($characters) > 128 || count(array_unique($characters, SORT_STRING)) !== count($characters)) {
            throw new \InvalidArgumentException('The alphabet must contain 1-128 distinct UTF-8 characters.');
        }
        if ($settings['normalization'] === 'ascii' && self::normalize($alphabet) === '') {
            throw new \InvalidArgumentException('The ASCII profile requires at least one English letter in the alphabet.');
        }
        $random = self::randomizer($settings['seed']);
        $output = [];
        $draws = 0;
        // normalize before selecting a prefix so length conditions remain nested for a fixed seed
        $target = $settings['text_length'] + ($settings['normalization'] === 'ascii' ? 1 : 0);
        while (count($output) < $target) {
            if (++$draws > max(10000, $target * 1000)) {
                throw new \RuntimeException('The selected alphabet could not produce enough normalized characters.');
            }
            $character = $characters[$random->getInt(0, count($characters) - 1)];
            if ($settings['normalization'] === 'ascii') {
                $character = preg_replace('/[^a-z \t\r\n\f\v]+/', ' ', strtolower($character));
                $character = preg_replace('/[ \t\r\n\f\v]+/', ' ', $character);
                if ($character === ' ' && ($output === [] || end($output) === ' ')) {
                    continue;
                }
            }
            $output[] = $character;
        }
        $text = implode('', array_slice($output, 0, $settings['text_length']));
        return ['text' => $text, 'metadata' => [
            'generator' => Mt19937::class, 'engine_mode' => 'MT_RAND_MT19937',
            'bounded_selection' => 'Random\\Randomizer::getInt', 'php_version' => PHP_VERSION,
            'seed' => $settings['seed'], 'alphabet_option' => $settings['alphabet'], 'alphabet' => $alphabet,
            'normalization' => $settings['normalization'] === 'ascii' ? self::NORMALIZATION : 'none',
            'normalization_rule' => $settings['normalization'] === 'ascii' ? self::NORMALIZATION_RULE : 'none',
            'raw_draws' => $draws, 'selected_length' => $settings['text_length'],
            'text_sha256' => hash('sha256', $text),
        ]];
    }

    public function patterns(string $text, int $length, int $count, int $seed, string $method = 'derived', string $custom = '', ?int $window = null): array
    {
        $characters = self::characters($text);
        if ($length < 1 || $count < 1 || $count > 100 || $length > count($characters)) {
            throw new \InvalidArgumentException('Invalid pattern dimensions.');
        }
        $patterns = $offsets = [];
        if ($method === 'custom') {
            $patterns = preg_split('/\r\n|\r|\n/', $custom);
            if (count($patterns) !== $count || count(array_unique($patterns, SORT_STRING)) !== $count) {
                throw new \InvalidArgumentException('Enter exactly the configured number of distinct custom patterns.');
            }
            foreach ($patterns as $pattern) {
                if (Workload::characters($pattern) !== $length) {
                    throw new \InvalidArgumentException('Every custom pattern must have the configured character length.');
                }
                $position = strpos($text, $pattern);
                $offsets[] = $position === false ? null : Workload::characters(substr($text, 0, $position));
            }
        } elseif ($method === 'derived') {
            $window = min($window ?? count($characters), count($characters));
            $remaining = $window - $length + 1;
            $random = self::randomizer($seed);
            $swaps = [];
            // sample offsets without replacement and keep only distinct patterns with non-whitespace edges
            while ($remaining > 0 && count($patterns) < $count) {
                $slot = $random->getInt(0, $remaining - 1);
                $offset = $swaps[$slot] ?? $slot;
                --$remaining;
                $swaps[$slot] = $swaps[$remaining] ?? $remaining;
                unset($swaps[$remaining]);
                $candidate = implode('', array_slice($characters, $offset, $length));
                if (preg_match('/(?:\A\s|\s\z)/u', $candidate) || in_array($candidate, $patterns, true)) {
                    continue;
                }
                $patterns[] = $candidate;
                $offsets[] = $offset;
            }
            if (count($patterns) !== $count) {
                throw new \InvalidArgumentException('The selection window does not contain enough distinct patterns of this length without whitespace edges.');
            }
        } else {
            throw new \InvalidArgumentException('Choose text-derived or custom patterns.');
        }
        $byteOffsets = array_map(static fn(?int $offset): ?int => $offset === null ? null
            : strlen(implode('', array_slice($characters, 0, $offset))), $offsets);
        return ['patterns' => $patterns, 'metadata' => [
            'method' => $method === 'derived' ? 'seeded-distinct-offsets-v1' : 'custom-exact-lines-v1',
            'seed' => $method === 'derived' ? $seed : null,
            'generator' => $method === 'derived' ? 'Random\\Engine\\Mt19937; MT_RAND_MT19937; Random\\Randomizer::getInt' : null,
            'selection_window_characters' => $method === 'derived' ? $window : null,
            'character_offsets' => $offsets, 'byte_offsets' => $byteOffsets,
            'offset_meaning' => $method === 'derived' ? 'selected substring position' : 'first occurrence or null when absent',
            'lengths_characters' => array_map([Workload::class, 'characters'], $patterns),
            'sha256' => array_map(static fn(string $pattern): string => hash('sha256', $pattern), $patterns),
        ]];
    }

    private function input(array $settings, string $text, array $source, array $patternData, string $profile): array
    {
        $metadata = [
            'schema' => 'dataset-preparation-v1', 'profile' => $profile, 'php_version' => PHP_VERSION,
            'configuration' => $settings, 'source' => $source, 'pattern_selection' => $patternData['metadata'],
            'text_sha256' => hash('sha256', $text),
            'patterns_sha256' => hash('sha256', json_encode($patternData['patterns'], JSON_THROW_ON_ERROR)),
            'sampling_design' => 'fixed input; measured runs are timing repetitions, not independently sampled texts',
        ];
        $generatedId = sprintf('DS-%s-n%d-m%d-p%d-%s', $settings['text_type'][0], $settings['text_length'],
            $settings['pattern_length'], $settings['pattern_count'], substr(hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)), 0, 10));
        return Workload::validate([
            'group_id' => $settings['group_id'] ?: $generatedId,
            'name' => 'Prepared ' . strtolower($settings['text_type']) . ' input',
            // The factorial matrix shares measurements across all five research hypotheses.
            'hypothesis' => $profile === self::PROFILE
                ? implode('; ', array_keys((require dirname(__DIR__) . '/config/hypotheses.php'))) : 'H1',
            'text_type' => $settings['text_type'], 'text' => $text, 'patterns' => $patternData['patterns'],
            'runs' => $settings['runs'], 'warmups' => $settings['warmups'],
            'seed' => $settings['text_type'] === 'Random' ? (string) $settings['seed'] : '',
            'source_reference' => $source['source_reference'] ?? 'Prepared random text; explicit Mt19937 seed and alphabet in dataset metadata',
            'source_sha256' => $source['source_sha256'] ?? '', 'source_offset' => isset($source['start_offset']) ? (string) $source['start_offset'] : '',
            'normalization' => $source['normalization'], 'generation_method' => 'dataset-preparation-v1/' . $profile,
            'sample_id' => 'fixed-input-1', 'dataset_metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    public function single(array $settings, ?NarrativeCorpus $corpus = null): array
    {
        $settings = self::settings($settings);
        if ($settings['text_type'] === 'Narrative') {
            if ($corpus === null) {
                throw new \InvalidArgumentException('Load the Alice corpus before selecting a narrative excerpt.');
            }
            $settings['normalization'] = 'ascii';
            $selected = $corpus->select($settings);
        } else {
            $selected = $this->random($settings);
            $selected['metadata']['source_id'] = 'mt19937-' . hash('sha256', json_encode($selected['metadata'], JSON_THROW_ON_ERROR));
        }
        $patterns = $this->patterns($selected['text'], $settings['pattern_length'], $settings['pattern_count'],
            $settings['pattern_seed'], $settings['pattern_selection'], $settings['custom_patterns']);
        return ['version' => 1, 'kind' => 'single', 'profile' => 'configurable-single-v1',
            'recipe' => $settings, 'inputs' => [$this->input($settings, $selected['text'], $selected['metadata'], $patterns, 'configurable-single-v1')]];
    }

    public function matrix(array $settings, NarrativeCorpus $corpus): array
    {
        $settings = self::settings($settings);
        $principal = self::settings(array_replace(self::defaults(), array_intersect_key($settings,
            array_flip(['seed', 'pattern_seed', 'source_id', 'narrative_selection', 'chapter', 'start_offset', 'justification']))));
        $baseSettings = array_replace($principal, ['text_length' => 5000]);
        $randomBase = $this->random($baseSettings);
        $randomBase['metadata']['source_id'] = 'mt19937-' . hash('sha256', json_encode($randomBase['metadata'], JSON_THROW_ON_ERROR));
        $narrativeBase = $corpus->select(array_replace($baseSettings, ['text_type' => 'Narrative']));
        $inputs = [];
        foreach (['Random' => $randomBase, 'Narrative' => $narrativeBase] as $type => $base) {
            foreach ([3, 8, 15] as $patternLength) {
                // choose one three-pattern set from the first 50 characters and reuse it at every text length
                $patternSet = $this->patterns($base['text'], $patternLength, 3, $principal['pattern_seed'], 'derived', '', 50);
                foreach ([50, 500, 5000] as $textLength) {
                    $text = substr($base['text'], 0, $textLength);
                    $source = array_replace($base['metadata'], [
                        'base_length' => 5000, 'base_sha256' => hash('sha256', $base['text']),
                        'selected_length' => $textLength, 'prefix_offset' => 0, 'text_sha256' => hash('sha256', $text),
                    ]);
                    if ($type === 'Narrative') {
                        $source['base_selection_method'] = $source['selection_method'];
                        $source['selection_method'] = 'prefix-of-recorded-base-v1';
                        $source['selected_chapters'] = array_column(array_values(array_filter($corpus->info()['chapters'],
                            static fn(array $chapter): bool => $chapter['start_offset'] < $source['start_offset'] + $textLength
                                && $chapter['start_offset'] + $chapter['length'] > $source['start_offset'])), 'chapter');
                    }
                    foreach ([1, 3] as $patternCount) {
                        $condition = array_replace($principal, ['text_type' => $type, 'text_length' => $textLength,
                            'pattern_length' => $patternLength, 'pattern_count' => $patternCount]);
                        $selected = $patternSet;
                        $selected['patterns'] = array_slice($selected['patterns'], 0, $patternCount);
                        foreach (['character_offsets', 'byte_offsets', 'lengths_characters', 'sha256'] as $key) {
                            $selected['metadata'][$key] = array_slice($selected['metadata'][$key], 0, $patternCount);
                        }
                        $selected['metadata']['corresponding_three_pattern_set'] = $patternSet['patterns'];
                        $inputs[] = $this->input($condition, $text, $source, $selected, self::PROFILE);
                    }
                }
            }
        }
        return ['version' => 1, 'kind' => 'matrix', 'profile' => self::PROFILE, 'recipe' => $principal,
            'inputs' => $inputs, 'input_groups' => 36, 'algorithm_conditions' => 108, 'planned_measurements' => 3240,
            'planned_warmups' => 216];
    }
}
