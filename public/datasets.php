<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';

use StringMatching\{DatasetGenerator, DatasetLibrary, NarrativeCorpus};

$library = new DatasetLibrary(getenv('STRING_MATCHING_DATASET_DIR') ?: dirname(__DIR__) . '/experiment-results/datasets');
$settings = $_SESSION['dataset_settings'] ?? DatasetGenerator::defaults();
$matrixSettings = $_SESSION['matrix_settings'] ?? DatasetGenerator::defaults();
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : null;
$record = null;
$error = null;
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
try {
    if (isset($_GET['download'])) {
        $download = is_string($_GET['download']) ? $_GET['download'] : '';
        if (in_array($download, ['source', 'corpus'], true)) {
            $source = is_string($_GET['source'] ?? null) ? $_GET['source'] : 'builtin';
            $contents = $download === 'source' ? $library->original($source) : $library->corpus($source)->text();
            $filename = $download === 'source' ? 'alice-original-source.txt' : 'alice-normalized-corpus.txt';
            header('Content-Type: text/plain; charset=UTF-8');
        } elseif ($id !== null && $download === 'manifest') {
            $contents = json_encode($library->manifest($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            $filename = 'dataset-manifest.json';
            header('Content-Type: application/json; charset=UTF-8');
        } elseif ($id !== null && in_array($download, ['csv', 'full'], true)) {
            $bundle = $library->read($id, true)['bundle'];
            if ($download === 'full' && $bundle['kind'] !== 'matrix') {
                throw new InvalidArgumentException('Choose a saved dataset matrix for full export.');
            }
            $group = is_string($_GET['group'] ?? null) ? $_GET['group'] : null;
            if ($download === 'csv' && $group === null) {
                throw new InvalidArgumentException('Select an input group to export.');
            }
            $contents = $library->csv($id, $download === 'full' ? null : $group);
            $filename = $download === 'full' ? 'input-matrix-seed-' . $bundle['recipe']['seed'] . '.csv' : 'selected-input.csv';
            header('Content-Type: text/csv; charset=UTF-8');
        } else {
            throw new InvalidArgumentException('Unknown dataset export.');
        }
        session_write_close();
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $contents;
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrf();
        $action = posted('action');
        if ($action === 'upload_source') {
            $upload = $_FILES['source_file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_uploaded_file($upload['tmp_name']) || $upload['size'] > 5000000) {
                throw new InvalidArgumentException('Upload the complete Alice UTF-8 plain-text file, at most 5 MB.');
            }
            $source = file_get_contents($upload['tmp_name']);
            if ($source === false) {
                throw new RuntimeException('Cannot read the narrative source upload.');
            }
            $settings['source_id'] = $library->preserveSource($source, $upload['name']);
            $_SESSION['dataset_settings'] = $settings;
            $_SESSION['notice'] = 'Original source preserved unchanged. Its normalized corpus is ready to inspect.';
            go('datasets.php');
        } elseif ($action === 'matrix') {
            $keys = array_flip(['seed', 'pattern_seed', 'source_id', 'narrative_selection', 'chapter', 'start_offset', 'justification']);
            $matrixSettings = DatasetGenerator::defaults();
            foreach (array_keys($keys) as $key) {
                if (isset($_POST[$key])) {
                    $matrixSettings[$key] = posted($key);
                }
            }
            $matrixSettings = DatasetGenerator::settings($matrixSettings);
            $_SESSION['matrix_settings'] = $matrixSettings;
            $bundle = (new DatasetGenerator())->matrix($matrixSettings, $library->corpus($matrixSettings['source_id']));
            $id = $library->save($library->stage($bundle));
            $_SESSION['notice'] = 'Dataset ready. Download the CSV for Batch Experiment.';
            go('datasets.php?id=' . $id);
        } elseif ($action === 'generate') {
            foreach (array_keys(DatasetGenerator::defaults()) as $key) {
                $settings[$key] = posted($key);
            }
            $settings = DatasetGenerator::settings($settings);
            $_SESSION['dataset_settings'] = $settings;
            $corpus = $settings['text_type'] === 'Narrative' ? $library->corpus($settings['source_id']) : null;
            $id = $library->stage((new DatasetGenerator())->single($settings, $corpus));
            $_SESSION['notice'] = 'Input preview generated. No algorithm was executed.';
            go('datasets.php?id=' . $id);
        } elseif ($action === 'save') {
            $groupIds = $_POST['group_ids'] ?? [];
            if (!is_array($groupIds)) {
                throw new InvalidArgumentException('Matrix group IDs must be a list.');
            }
            $id = $library->save(posted('dataset_id'), posted('saved_group_id'), $groupIds);
            $_SESSION['notice'] = 'Dataset saved with exact inputs, settings and calculated hashes.';
            go('datasets.php?id=' . $id);
        } elseif ($action === 'reproduce') {
            $id = $library->reproduce(posted('dataset_id'));
            $_SESSION['notice'] = 'Regenerated texts and patterns match the saved inputs exactly. No algorithm was executed.';
            go('datasets.php?id=' . $id);
        } else {
            throw new InvalidArgumentException('Choose a dataset preparation action.');
        }
    }
    if ($id !== null) {
        $record = $library->read($id);
        if ($record['bundle']['kind'] === 'matrix') {
            $matrixSettings = $record['bundle']['recipe'];
        } else {
            $settings = $record['bundle']['recipe'];
        }
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
    if (isset($_GET['download'])) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        session_write_close();
        exit($error);
    }
}
$corpus = null;
try {
    $corpus = $library->corpus((string) $settings['source_id']);
    $sources = $library->sources();
    $saved = $library->saved();
} catch (Throwable $exception) {
    $error ??= $exception->getMessage();
    $sources = $saved = [];
}
$isSaved = $id !== null && in_array($id, array_column($saved, 'id'), true);
$currentInput = $record['bundle']['inputs'][0] ?? null;
if ($record !== null && is_string($_GET['group'] ?? null)) {
    foreach ($record['bundle']['inputs'] as $input) {
        if ($input['group_id'] === $_GET['group']) {
            $currentInput = $input;
            break;
        }
    }
}
pageStart('Generate Datasets', 'datasets');
?>
<p>Generate the required datasets, download the CSV, then upload it to run the experiments.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif ?>
<section>
<h2>1. Generate the dataset</h2>
<p>Creates all 36 input groups using random text and Alice narrative excerpts.</p>
<p class="help">Includes text lengths of 50, 500 and 5,000 characters, pattern lengths of 3, 8 and 15, and 1 or 3 patterns.</p>
<form id="dataset-form" method="post" action="datasets.php">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<details>
<summary>Generate a different dataset</summary>
<label for="dataset-seed">Dataset seed</label>
<input id="dataset-seed" name="seed" type="number" min="0" max="4294967295" step="1" required value="<?= h((string) $matrixSettings['seed']) ?>" aria-describedby="dataset-seed-help">
<p id="dataset-seed-help" class="help">Use another number, such as 756, for new random text and a seeded Alice excerpt. Use the same number to reproduce a dataset.</p>
</details>
<button name="action" value="matrix">Generate Input Matrix</button>
</form>
</section>
<?php if ($record !== null): $bundle = $record['bundle']; $planned = array_sum(array_column($bundle['inputs'], 'runs')) * 3; ?>
<section id="dataset-preview">
<h2><?= $bundle['kind'] === 'matrix' ? '2. Download your dataset' : 'Input ready' ?></h2>
<?php if ($bundle['kind'] === 'matrix'): ?><p>Dataset seed: <strong><?= h((string) $bundle['recipe']['seed']) ?></strong></p><?php endif ?>
<p><strong><?= count($bundle['inputs']) ?> input group<?= count($bundle['inputs']) === 1 ? '' : 's' ?> · <?= count($bundle['inputs']) * 3 ?> algorithm conditions · <?= number_format($planned) ?> planned measured runs</strong></p>
<?php if ($isSaved): ?>
<div class="dataset-actions"><?php if ($bundle['kind'] === 'matrix'): ?><a class="download-primary" href="?id=<?= h($id) ?>&amp;download=full">Export Full CSV</a><a href="batch.php">3. Upload and run the CSV</a><?php else: ?><a class="download-primary" href="?id=<?= h($id) ?>&amp;download=csv&amp;group=<?= h(rawurlencode($currentInput['group_id'])) ?>">Export Selected Input CSV</a><a href="./?dataset=<?= h($id) ?>&amp;group=<?= h(rawurlencode($currentInput['group_id'])) ?>">Try this input manually</a><?php endif ?></div>
<?php else: ?>
<form method="post" action="datasets.php"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="dataset_id" value="<?= h($id) ?>"><input type="hidden" name="saved_group_id" value=""><button name="action" value="save">Save Dataset</button></form>
<?php endif ?>
<details id="input-preview"><summary>Preview the generated inputs</summary>
<?php if (count($bundle['inputs']) > 1): ?>
<form class="dataset-inspector" method="get" action="datasets.php#dataset-preview"><input type="hidden" name="id" value="<?= h($id) ?>">
<label for="inspect-group">Choose an input</label><div class="dataset-actions"><select id="inspect-group" name="group"><?php foreach ($bundle['inputs'] as $input): ?><option value="<?= h($input['group_id']) ?>" <?= $currentInput['group_id'] === $input['group_id'] ? 'selected' : '' ?>><?= h($input['text_type'] . ' · ' . $input['text_length'] . ' characters · ' . $input['pattern_lengths'][0] . '-character patterns × ' . $input['pattern_count']) ?></option><?php endforeach ?></select><button class="secondary" type="submit">View Input</button></div></form>
<?php endif ?>
<h3><?= h($currentInput['text_type'] . ' · ' . $currentInput['text_length'] . ' characters') ?></h3>
<textarea readonly rows="4" aria-label="Exact input text"><?php echo "\n", h($currentInput['text']); ?></textarea>
<p><strong>Patterns to find</strong></p><ul class="dataset-patterns"><?php foreach ($currentInput['patterns'] as $pattern): ?><li><code><?= h($pattern) ?></code></li><?php endforeach ?></ul>
</details>
</section>
<?php endif ?>
<?php session_write_close(); ?>
</main></body></html>