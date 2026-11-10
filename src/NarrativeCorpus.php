<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class NarrativeCorpus
{
    private string $text;
    private array $chapters = [];
    private string $sourceHash;

    /** Normalize a working copy without changing the source. */
    public function __construct(string $original)
    {
        if (strlen($original) > 5000000 || !preg_match('//u', $original) || str_contains($original, "\0")) {
            throw new \InvalidArgumentException('Alice source must be valid UTF-8, contain no NUL bytes and be at most 5 MB.');
        }
        if (!preg_match("/^Title:\\s+Alice['’]s Adventures in Wonderland\\s*$/miu", $original)
            || !preg_match('/^Author:\s+Lewis Carroll\s*$/mi', $original)) {
            throw new \InvalidArgumentException('Load the complete Alice in Wonderland Gutenberg plain-text source, including its title and author.');
        }
        $this->sourceHash = hash('sha256', $original);
        $working = str_replace(["\r\n", "\r"], "\n", $original);
        if (!preg_match('/^\*\*\* START OF (?:THE|THIS) PROJECT GUTENBERG EBOOK[^\n]*\*\*\*[ \t]*$/m', $working, $start, PREG_OFFSET_CAPTURE)
            || !preg_match('/^\*\*\* END OF (?:THE|THIS) PROJECT GUTENBERG EBOOK[^\n]*\*\*\*[ \t]*$/m', $working, $end, PREG_OFFSET_CAPTURE)) {
            throw new \InvalidArgumentException('The Gutenberg start/end markers are missing.');
        }
        $bodyStart = $start[0][1] + strlen($start[0][0]);
        if ($end[0][1] <= $bodyStart) {
            throw new \InvalidArgumentException('The Gutenberg markers are out of order.');
        }
        $body = substr($working, $bodyStart, $end[0][1] - $bodyStart);
        preg_match_all('/^CHAPTER ([IVXLCDM]+)\.[ \t]*\n([^\n]+)\n/m', $body, $headings, PREG_OFFSET_CAPTURE);
        if (count($headings[0]) !== 12) {
            throw new \InvalidArgumentException('Expected twelve chapter headings in the Alice source.');
        }
        $corpus = '';
        foreach ($headings[0] as $index => [$heading, $position]) {
            $chapter = $headings[1][$index][0];
            $contentStart = $position + strlen($heading);
            $contentEnd = $headings[0][$index + 1][1] ?? strlen($body);
            $text = DatasetGenerator::normalize(substr($body, $contentStart, $contentEnd - $contentStart));
            if ($text === '' || isset($this->chapters[$chapter])) {
                throw new \InvalidArgumentException('Empty or duplicate chapter in the source.');
            }
            // join chapters with one space so offsets always refer to the same normalized corpus
            $offset = strlen($corpus) + ($corpus === '' ? 0 : 1);
            $this->chapters[$chapter] = ['chapter' => $chapter, 'title' => trim($headings[2][$index][0]),
                'start_offset' => $offset, 'length' => strlen($text), 'sha256' => hash('sha256', $text)];
            $corpus .= ($corpus === '' ? '' : ' ') . $text;
        }
        $this->text = $corpus;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function info(): array
    {
        return ['source_id' => 'alice-sha256-' . $this->sourceHash, 'source_sha256' => $this->sourceHash,
            'source_reference' => "Lewis Carroll, Alice's Adventures in Wonderland; Project Gutenberg eBook 11; https://www.gutenberg.org/ebooks/11",
            'normalization' => DatasetGenerator::NORMALIZATION, 'normalization_rule' => DatasetGenerator::NORMALIZATION_RULE,
            'wrapper_removal' => 'remove Gutenberg markers/wrappers, title page, table of contents, chapter numbers and chapter titles',
            'normalized_length' => strlen($this->text), 'normalized_sha256' => hash('sha256', $this->text),
            'chapters' => array_values($this->chapters), 'offset_unit' => 'zero_based_normalized_character_offset'];
    }

    public function select(array $settings): array
    {
        $settings = DatasetGenerator::settings($settings);
        $length = $settings['text_length'];
        $method = $settings['narrative_selection'];
        $baseLength = $method === 'seeded' ? max(5000, $length) : $length;
        $maxOffset = strlen($this->text) - $baseLength;
        if ($maxOffset < 0) {
            throw new \InvalidArgumentException('The corpus is shorter than the requested base passage.');
        }
        $seed = null;
        if ($method === 'seeded') {
            $seed = $settings['seed'];
            $offset = DatasetGenerator::randomizer($seed)->getInt(0, $maxOffset);
        } elseif ($method === 'chapter') {
            $chapter = $this->chapters[$settings['chapter']] ?? null;
            if ($chapter === null || $chapter['length'] < $length) {
                throw new \InvalidArgumentException('The selected chapter cannot supply the requested number of characters.');
            }
            $offset = $chapter['start_offset'];
        } else {
            $offset = $settings['start_offset'];
        }
        if ($method !== 'seeded' && trim($settings['justification']) === '') {
            throw new \InvalidArgumentException('Record a justification for an intentionally selected chapter or offset.');
        }
        if ($offset > strlen($this->text) - $length) {
            throw new \InvalidArgumentException('Start offset and length exceed the normalized corpus.');
        }
        $text = substr($this->text, $offset, $length);
        $sections = [];
        foreach ($this->chapters as $chapter) {
            if ($chapter['start_offset'] < $offset + $length && $chapter['start_offset'] + $chapter['length'] > $offset) {
                $sections[] = $chapter['chapter'];
            }
        }
        return ['text' => $text, 'metadata' => $this->info() + [
            'selection_method' => $method, 'selection_seed' => $seed,
            'selection_generator' => $seed === null ? null : 'Random\\Engine\\Mt19937; MT_RAND_MT19937; Random\\Randomizer::getInt',
            'start_offset' => $offset, 'selected_length' => $length, 'selected_chapters' => $sections,
            'selected_chapter' => $method === 'chapter' ? $settings['chapter'] : null,
            'base_length' => $baseLength, 'base_sha256' => hash('sha256', substr($this->text, $offset, $baseLength)),
            'justification' => $settings['justification'], 'text_sha256' => hash('sha256', $text),
        ]];
    }
}