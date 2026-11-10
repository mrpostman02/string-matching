<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class BatchInput
{
    public const COLUMNS = [
        'Experiment Group ID', 'Text Type', 'Text Length', 'Pattern Length', 'Number of Patterns',
        'Input Text', 'Pattern 1', 'Pattern 2', 'Pattern 3', 'Number of Runs', 'Number of Warm-ups',
        'Experiment Name', 'Hypothesis', 'Source Reference', 'Seed', 'Generation Method',
        'Source SHA256', 'Source Offset', 'Normalization', 'Sample ID',
    ];
    private const FIELDS = [
        'group_id', 'text_type', 'text_length', 'pattern_length', 'pattern_count',
        'text', 'pattern_1', 'pattern_2', 'pattern_3', 'runs', 'warmups',
        'name', 'hypothesis', 'source_reference', 'seed', 'generation_method',
        'source_sha256', 'source_offset', 'normalization', 'sample_id',
    ];

    /** reject malformed quotes because fgetcsv can otherwise accept damaged records */
    private static function checkSyntax(string $csv): void
    {
        $state = 'start';
        for ($i = 0, $length = strlen($csv); $i < $length; ++$i) {
            $char = $csv[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if ($i + 1 < $length && $csv[$i + 1] === '"') {
                        ++$i;
                    } else {
                        $state = 'closed';
                    }
                }
                continue;
            }
            if ($state === 'closed' && $char !== ',' && $char !== "\r" && $char !== "\n") {
                throw new \InvalidArgumentException('Unexpected content after a closing CSV quote.');
            }
            if ($char === ',' || $char === "\r" || $char === "\n") {
                if ($char === "\r" && ($csv[$i + 1] ?? '') !== "\n") {
                    throw new \InvalidArgumentException('Use LF or CRLF CSV record endings.');
                }
                $state = 'start';
            } elseif ($char === '"') {
                if ($state !== 'start') {
                    throw new \InvalidArgumentException('Quotes inside unquoted CSV fields must be escaped and enclosed.');
                }
                $state = 'quoted';
            } else {
                $state = 'unquoted';
            }
        }
        if ($state === 'quoted') {
            throw new \InvalidArgumentException('CSV has an unterminated quoted field.');
        }
    }

    private static function headerKey(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim($value)));
    }

    private static function integer(string $value, string $label, int $min = 1): int
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value)
            || ($number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]])) === false) {
            throw new \InvalidArgumentException("$label must be a whole number >= $min.");
        }
        return $number;
    }

    /** validate every row before offering execution and reject every occurrence of a duplicate group id */
    public function parse(string $original): array
    {
        if ($original === '' || strlen($original) > 10000000 || !preg_match('//u', $original) || str_contains($original, "\0")) {
            throw new \InvalidArgumentException('Upload a nonempty UTF-8 CSV of at most 10 MB, without NUL bytes.');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $original);
        self::checkSyntax($csv);
        $handle = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!$handle) {
            throw new \RuntimeException('Cannot parse the input CSV.');
        }
        $records = [];
        try {
            fwrite($handle, $csv);
            rewind($handle);
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header)) {
                throw new \InvalidArgumentException('CSV header is missing.');
            }
            $aliases = [];
            foreach (self::COLUMNS as $index => $label) {
                $aliases[self::headerKey($label)] = self::FIELDS[$index];
                $aliases[self::headerKey(self::FIELDS[$index])] = self::FIELDS[$index];
            }
            $aliases += ['datasetmetadatajson' => 'dataset_metadata_json', 'inputsha256' => 'text_sha256', 'textsha256' => 'text_sha256',
                'patternssha256' => 'patterns_sha256'];
            $fields = [];
            foreach ($header as $label) {
                $normalizedLabel = self::headerKey($label);
                $key = $aliases[$normalizedLabel] ?? null;
                if ($key === null && preg_match('/\Apattern([1-9][0-9]*)\z/', $normalizedLabel, $match) && (int) $match[1] <= 100) {
                    $key = 'pattern_' . $match[1];
                }
                if ($key === null || in_array($key, $fields, true)) {
                    throw new \InvalidArgumentException('CSV contains an unknown or duplicate header: ' . $label);
                }
                $fields[] = $key;
            }
            $required = ['group_id', 'text_type', 'text_length', 'pattern_length', 'pattern_count',
                'text', 'pattern_1', 'runs', 'warmups'];
            if (array_diff($required, $fields)) {
                throw new \InvalidArgumentException('CSV is missing columns: ' . implode(', ', array_diff($required, $fields)));
            }
            $recordNumber = 1;
            while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                ++$recordNumber;
                if ($values === [null]) {
                    continue;
                }
                if (count($records) >= 1000) {
                    throw new \InvalidArgumentException('CSV supports at most 1,000 input groups.');
                }
                $record = ['record' => $recordNumber, 'group_id' => '', 'input' => null, 'errors' => []];
                if (count($values) !== count($fields)) {
                    $record['errors'][] = 'Column count differs from the header.';
                    $records[] = $record;
                    continue;
                }
                $row = array_combine($fields, $values);
                $record['group_id'] = $row['group_id'];
                try {
                    $textLength = self::integer($row['text_length'], 'Text Length');
                    $patternLength = self::integer($row['pattern_length'], 'Pattern Length');
                    $patternCount = self::integer($row['pattern_count'], 'Number of Patterns');
                    if ($patternCount > 100) {
                        throw new \InvalidArgumentException('The CSV format supports at most 100 patterns.');
                    }
                    $patterns = [];
                    $lastPattern = max($patternCount, 3);
                    foreach ($fields as $field) {
                        if (preg_match('/\Apattern_([0-9]+)\z/', $field, $match)) {
                            $lastPattern = max($lastPattern, (int) $match[1]);
                        }
                    }
                    for ($index = 1; $index <= $lastPattern; ++$index) {
                        $pattern = $row['pattern_' . $index] ?? '';
                        if ($index <= $patternCount) {
                            if ($pattern === '') {
                                throw new \InvalidArgumentException("Pattern $index is missing.");
                            }
                            $patterns[] = $pattern;
                        } elseif ($pattern !== '') {
                            throw new \InvalidArgumentException('Extra pattern fields disagree with Number of Patterns.');
                        }
                    }
                    $input = ['group_id' => $row['group_id'], 'text_type' => $row['text_type'],
                        'text' => $row['text'], 'patterns' => $patterns,
                        'runs' => self::integer($row['runs'], 'Number of Runs'),
                        'warmups' => self::integer($row['warmups'], 'Number of Warm-ups', 0)];
                    foreach (array_slice(self::FIELDS, 11) as $key) {
                        if (isset($row[$key]) && $row[$key] !== '') {
                            $input[$key] = $row[$key];
                        }
                    }
                    foreach (['dataset_metadata_json', 'text_sha256', 'patterns_sha256'] as $key) {
                        if (isset($row[$key]) && $row[$key] !== '') {
                            $input[$key] = $row[$key];
                        }
                    }
                    $input = Workload::validate($input);
                    if ($input['text_length'] !== $textLength) {
                        throw new \InvalidArgumentException("Declared Text Length $textLength differs from actual {$input['text_length']} characters.");
                    }
                    foreach ($input['pattern_lengths'] as $actualLength) {
                        if ($actualLength !== $patternLength) {
                            throw new \InvalidArgumentException("Declared Pattern Length $patternLength differs from actual $actualLength characters.");
                        }
                    }
                    $record['input'] = $input;
                } catch (\InvalidArgumentException $exception) {
                    $record['errors'][] = $exception->getMessage();
                }
                $records[] = $record;
            }
        } finally {
            fclose($handle);
        }
        if ($records === []) {
            throw new \InvalidArgumentException('CSV contains no input groups.');
        }
        $counts = array_count_values(array_column($records, 'group_id'));
        foreach ($records as &$record) {
            if ($record['group_id'] !== '' && $counts[$record['group_id']] > 1) {
                $record['errors'][] = 'Duplicate Group ID: every occurrence is rejected.';
            }
        }
        unset($record);
        $valid = array_values(array_filter($records, static fn(array $r): bool => $r['errors'] === []));
        $inputs = array_column($valid, 'input');
        return [
            'input_file_sha256' => hash('sha256', $original), 'records' => $records, 'inputs' => $inputs,
            'valid_groups' => count($valid), 'invalid_groups' => count($records) - count($valid),
            'algorithm_conditions' => count($valid) * 3,
            'planned_measurements' => array_sum(array_column($inputs, 'runs')) * 3,
            'planned_warmups' => array_sum(array_column($inputs, 'warmups')) * 3,
            'complete_matrix' => self::isCompleteMatrix($inputs) && count($valid) === count($records),
        ];
    }

    public static function isCompleteMatrix(array $inputs): bool
    {
        if (count($inputs) !== 36) {
            return false;
        }
        $seen = [];
        foreach ($inputs as $input) {
            $lengths = array_unique($input['pattern_lengths']);
            if (!in_array($input['text_type'], ['Random', 'Narrative'], true)
                || !in_array($input['text_length'], [50, 500, 5000], true)
                || count($lengths) !== 1 || !in_array(reset($lengths), [3, 8, 15], true)
                || !in_array($input['pattern_count'], [1, 3], true) || $input['runs'] !== 30 || $input['warmups'] !== 2) {
                return false;
            }
            $key = implode(':', [$input['text_type'], $input['text_length'], reset($lengths), $input['pattern_count']]);
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }
        return count($seen) === 36;
    }

    /** leave inputs blank so the template cannot run before real texts and patterns are supplied */
    public function template(): string
    {
        $rows = [];
        foreach (['Random', 'Narrative'] as $type) {
            foreach ([50, 500, 5000] as $textLength) {
                foreach ([3, 8, 15] as $patternLength) {
                    foreach ([1, 3] as $patternCount) {
                        $rows[] = array_combine(self::COLUMNS, [
                            sprintf('SM-%s-n%d-m%d-p%d', $type, $textLength, $patternLength, $patternCount),
                            $type, $textLength, $patternLength, $patternCount, '', '', '', '', 30, 2,
                            '', '', '', '', '', '', '', 'none', 'fixed-input-1',
                        ]);
                    }
                }
            }
        }
        return ResearchExporter::csv($rows, self::COLUMNS);
    }
}