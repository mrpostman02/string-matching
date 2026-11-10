<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';

use StringMatching\{BatchInput, AtomicFiles, WorkerLauncher, ExperimentEngine};

$error = null;
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$id = is_string($_GET['session'] ?? null) ? $_GET['session'] : null;
$previewId = is_string($_GET['preview'] ?? null) ? $_GET['preview'] : ($_SESSION['batch_preview_id'] ?? null);
$preview = null;
$pendingDirectory = $repository->root() . '/pending';
if (!is_dir($pendingDirectory) && !mkdir($pendingDirectory, 0775, true) && !is_dir($pendingDirectory)) {
    throw new RuntimeException('Cannot create upload preview directory.');
}
function previewPath(string $directory, string $id): string
{
    if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) {
        throw new InvalidArgumentException('Invalid preview identifier.');
    }
    return $directory . '/' . $id . '.csv';
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrf();
        $action = posted('action');
        if ($action === 'upload') {
            $upload = $_FILES['input_csv'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file($upload['tmp_name'])) {
                throw new InvalidArgumentException('The file could not be uploaded. Choose a CSV file no larger than 10 MB.');
            }
            if ($upload['size'] > 10000000) {
                throw new InvalidArgumentException('CSV must be at most 10 MB.');
            }
            $csv = file_get_contents($upload['tmp_name']);
            if ($csv === false) {
                throw new RuntimeException('Cannot read the upload.');
            }
            (new BatchInput())->parse($csv);
            $previewId = bin2hex(random_bytes(16));
            AtomicFiles::write(previewPath($pendingDirectory, $previewId), $csv);
            $_SESSION['batch_preview_id'] = $previewId;
            go('batch.php?preview=' . $previewId);
        } elseif ($action === 'run_all') {
            if (posted('confirmed') !== 'yes') {
                throw new InvalidArgumentException('Confirm this exact execution plan before starting.');
            }
            $previewId = posted('preview_id');
            $csv = file_get_contents(previewPath($pendingDirectory, $previewId));
            if ($csv === false) {
                throw new RuntimeException('Preview input is unavailable. Upload it again.');
            }
            $preview = (new BatchInput())->parse($csv);
            if (!hash_equals($preview['input_file_sha256'], posted('input_hash')) || $preview['invalid_groups'] > 0) {
                throw new InvalidArgumentException('The CSV changed or contains invalid groups. Review a fresh preview.');
            }
            $id = $_SESSION['preview_sessions'][$previewId] ?? null;
            if ($id === null) {
                $plan = $repository->create($preview['inputs'], array_keys(ExperimentEngine::ALGORITHMS), 'batch',
                    $preview['input_file_sha256'], $csv);
                $id = $plan['session_id'];
                $_SESSION['preview_sessions'][$previewId] = $id;
            }
            $repository->confirm($id, 'web CSV preview confirmation checkbox and Run All Experiments button');
            $_SESSION['notice'] = 'Experiments started. Progress appears below.';
            try {
                WorkerLauncher::launch($repository, $id);
            } catch (Throwable $exception) {
                $_SESSION['notice'] = 'Execution confirmed; automatic launch failed: ' . $exception->getMessage();
                if (!WorkerLauncher::active($repository, $id)) {
                    $repository->status($id, ['state' => 'launch_failed',
                        'completed' => count(glob($repository->path($id) . '/journal/*.json') ?: []),
                        'error' => $exception->getMessage()]);
                }
            }
            go('batch.php?session=' . $id);
        } elseif ($action === 'resume') {
            $id = posted('session_id');
            WorkerLauncher::launch($repository, $id);
            $_SESSION['notice'] = 'Resuming the remaining experiments. Saved measurements will be kept.';
            go('batch.php?session=' . $id);
        } elseif ($action === 'observe') {
            $id = posted('session_id');
            $repository->observe($id, posted('observation'));
            $_SESSION['notice'] = 'Research observation saved without running experiments.';
            go('batch.php?session=' . $id);
        } else {
            throw new InvalidArgumentException('Unknown batch action.');
        }
    }
    if ($previewId !== null && $id === null) {
        $csv = file_get_contents(previewPath($pendingDirectory, $previewId));
        if ($csv === false) {
            throw new RuntimeException('Cannot read the preview.');
        }
        $preview = (new BatchInput())->parse($csv);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
pageStart('Upload and Run Datasets', 'batch');
?>
<?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif ?>

<section><h2>1. Upload your dataset CSV</h2>
<p>Choose the file downloaded from <a href="datasets.php">Generate Datasets</a>.</p>
<form method="post" action="batch.php" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<label for="csv">Dataset CSV</label><input id="csv" type="file" name="input_csv" accept=".csv,text/csv" required>
<button name="action" value="upload">Upload CSV</button>
</form></section>

<?php if ($preview !== null): ?>
<section><h2>2. Review and run</h2>
<p><strong><?= (int) $preview['valid_groups'] ?> valid groups · <?= (int) $preview['invalid_groups'] ?> invalid groups</strong></p>
<p><?= (int) $preview['algorithm_conditions'] ?> algorithm conditions · <strong><?= number_format($preview['planned_measurements']) ?> planned measured executions</strong></p>
<p>Naive, KMP and Aho-Corasick will search the same text and patterns for each input.</p>
<?php if ($preview['invalid_groups'] === 0): ?>
<form method="post" action="batch.php" data-batch-start>
<input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="preview_id" value="<?= h($previewId) ?>"><input type="hidden" name="input_hash" value="<?= h($preview['input_file_sha256']) ?>">
<label><input type="checkbox" name="confirmed" value="yes" required> I am ready to run all <?= number_format($preview['planned_measurements']) ?> measurements.</label>
<button name="action" value="run_all">Run All Experiments</button>
<p class="batch-loading" data-batch-pending role="status" hidden><span class="batch-spinner" aria-hidden="true"></span>Starting experiments...</p>
</form>
<?php else: ?>
<p class="notice error">Fix these rows and upload the CSV again before running.</p>
<ul><?php foreach ($preview['records'] as $record): foreach ($record['errors'] as $rowError): ?><li>Row <?= (int) $record['record'] ?>: <?= h($rowError) ?></li><?php endforeach; endforeach ?></ul>
<?php endif ?>
<details><summary>Preview uploaded inputs</summary>
<?php foreach ($preview['inputs'] as $input): ?>
<details><summary><?= h($input['text_type'] . ' · ' . $input['text_length'] . ' characters · ' . $input['pattern_count'] . ' patterns') ?></summary>
<pre><?= h($input['text']) ?></pre><p>Patterns: <?php foreach ($input['patterns'] as $pattern): ?><code><?= h($pattern) ?></code> <?php endforeach ?></p>
</details>
<?php endforeach ?>
</details></section>
<?php endif ?>
<?php if ($id !== null): ?>
<?php try {
    $plan = $repository->plan($id);
    $status = $repository->status($id);
    $active = WorkerLauncher::active($repository, $id);
    $committed = count(glob($repository->path($id) . '/journal/*.json') ?: []);
    $finished = in_array($status['state'], ['complete', 'complete_with_failures', 'failed', 'launch_failed'], true);
    $refresh = !$finished && ($active || $status['state'] === 'confirmed');
?>
<section <?= $refresh ? 'data-refresh-status' : '' ?>><h2>Experiment progress</h2>
<p class="batch-loading" role="status"><?php if ($refresh): ?><span class="batch-spinner" aria-hidden="true"></span><?php endif ?><strong><?= h($refresh ? ($active ? 'Running experiments...' : 'Starting experiments...') : ($status['state'] === 'running' ? 'Stopped before completion - saved measurements are safe' : runStatus($status['state']))) ?></strong></p>
<progress max="<?= (int) $plan['planned_measurements'] ?>" value="<?= $committed ?>" aria-label="Experiment progress"><?= $committed ?></progress>
<p><?= number_format($committed) ?> / <?= number_format($plan['planned_measurements']) ?> measurements saved</p>
<?php if ($refresh): ?><p class="help">Progress updates automatically. You can return to this page later.</p><?php endif ?>
<?php if (!empty($status['error'])): ?><p class="notice error"><?= h($status['error']) ?></p><?php endif ?>
<?php if (!$active && $committed < $plan['planned_measurements']): ?>
<form method="post" data-batch-start><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="session_id" value="<?= h($id) ?>"><button name="action" value="resume">Resume Experiments</button>
<p class="batch-loading" data-batch-pending role="status" hidden><span class="batch-spinner" aria-hidden="true"></span>Resuming experiments...</p>
</form>
<?php endif ?>
<a href="batch.php?session=<?= h($id) ?>">Refresh progress</a>
</section>
<?php if (is_file($repository->path($id) . '/summary.csv')): results($repository, $id); endif ?>

<?php } catch (Throwable $exception) { echo '<p class="notice error">' . h($exception->getMessage()) . '</p>'; } ?>
<?php endif ?>
<?php $previous = array_filter($repository->sessions(), static fn(array $session): bool => $session['mode'] === 'batch'); if ($previous !== []): ?>
<details><summary>Previous experiment runs</summary><ul>
<?php foreach ($previous as $session): ?><li><a href="batch.php?session=<?= h($session['session_id']) ?>"><?= count($session['inputs']) ?> inputs · <?= h($session['created_at']) ?></a></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<script src="progress.js" defer></script>
<?php session_write_close(); ?>
</main></body></html>