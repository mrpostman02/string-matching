<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use StringMatching\{SessionRepository, ResearchExporter, AtomicFiles};

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
}
$repository = new SessionRepository(getenv('STRING_MATCHING_DATA_DIR') ?: dirname(__DIR__) . '/experiment-results/sessions');
$sessionDirectory = $repository->root() . '/http-sessions';
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Cannot create the local HTTP session directory.');
}
session_save_path($sessionDirectory);
session_name('STRINGMATCHINGSESSID');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
if (!session_start()) {
    throw new RuntimeException('Cannot start the local research session.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];
function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function posted(string $key): string
{
    if (!is_string($_POST[$key] ?? null)) {
        throw new InvalidArgumentException('Missing or invalid field: ' . $key);
    }
    return $_POST[$key];
}
function checkCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'], posted('csrf'))) {
        throw new InvalidArgumentException('The form expired or exceeded the upload limit. Reload and try again.');
    }
}
function go(string $url): never
{
    session_write_close();
    header('Location: ' . $url, true, 303);
    exit;
}
function timing(mixed $value): string
{
    return $value === null ? 'N/A' : number_format((float) $value, 3, '.', ',') . ' µs';
}
function pageStart(string $title, string $active): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title><link rel="stylesheet" href="style.css"></head><body><main>';
    echo '<nav class="mode-nav" aria-label="Experiment mode">';
    foreach (['datasets' => ['datasets.php', '1. Generate Datasets'], 'batch' => ['batch.php', '2. Upload & Run'], 'manual' => ['./?manual=1', 'Manual Test']] as $key => [$url, $label]) {
        echo '<a href="' . $url . '"' . ($active === $key ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    echo '</nav><h1>' . h($title) . '</h1>';
}
function runStatus(string $state): string
{
    return match ($state) {
        'complete' => 'Completed',
        'complete_with_failures' => 'Completed with some failed checks',
        'running' => 'Running',
        'confirmed' => 'Starting',
        'awaiting_confirmation' => 'Waiting for confirmation',
        'checkpointed' => 'Ready to resume',
        'failed', 'launch_failed' => 'Stopped — completed measurements are saved',
        default => $state,
    };
}
function downloads(string $id): void
{
    echo '<p class="downloads"><a class="download-primary" href="download.php?session=' . h($id) . '&amp;file=runs.csv">Download Results CSV</a>';
    echo '<a href="download.php?session=' . h($id) . '&amp;file=summary.csv">Download Summary CSV</a></p>';
}
function results(SessionRepository $repository, string $id): void
{
    $plan = $repository->plan($id);
    $rows = $repository->rows($id);
    $summaries = (new ResearchExporter($repository))->summaries($plan, $rows);
    $failed = count(array_filter($rows, static fn(array $row): bool => $row['validation_status'] !== 'PASS'));
    echo '<section><h2>Results</h2><p>' . count($rows) . ' measurements saved. '
        . ($rows === [] ? 'No measurements have completed yet.' : ($failed ? $failed . ' need review.' : 'All completed matching checks passed.')) . '</p>';
    downloads($id);
    $multipleInputs = count($plan['inputs']) > 1;
    if ($multipleInputs) {
        echo '<details><summary>View results by input</summary>';
    }
    echo '<div class="comparison-scroll"><table class="comparison-table"><thead><tr><th>Input / Algorithm</th><th>Measured runs</th><th>Mean total time</th><th>Matching check</th></tr></thead><tbody>';
    foreach ($summaries as $summary) {
        $label = $multipleInputs ? $summary['text_type'] . ' · ' . $summary['text_length_characters'] . ' characters · ' . implode(', ', json_decode($summary['pattern_lengths_json'], true, 64, JSON_THROW_ON_ERROR)) . '-character patterns × ' . $summary['pattern_count'] . ' · ' : '';
        echo '<tr><th>' . h($label . $summary['algorithm']) . '</th><td>' . h($summary['total_measured_runs'] . '/' . $summary['requested_runs'])
            . '</td><td>' . h(timing($summary['mean_total_us'])) . '</td><td>' . h($summary['validation_status'])
            . ($summary['condition_complete'] ? '' : ' (in progress)') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($multipleInputs) {
        echo '</details>';
    } else {
        $input = $plan['inputs'][0];
        foreach ($rows as $row) {
            if ($row['positions'] === null) {
                continue;
            }
            echo '<h3>Matches found</h3><p class="help">Positions start at 0.</p>';
            foreach ($input['patterns'] as $index => $pattern) {
                echo '<p><code>' . h($pattern) . '</code>: ' . count($row['positions'][$index]) . ' matches</p><pre>'
                    . h(json_encode($row['positions'][$index], JSON_THROW_ON_ERROR)) . '</pre>';
            }
            break;
        }
    }
    echo '</section>';
}