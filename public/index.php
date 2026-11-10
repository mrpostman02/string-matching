<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_GET === []) {
    go('datasets.php');
}

use StringMatching\{Workload, ExperimentEngine, SessionRunner, ResearchExporter};

$inputs = $_SESSION['manual_inputs_v2'] ?? ['group_id' => 'manual-' . gmdate('Ymd-His'),
    'name' => 'Experiment 001', 'hypothesis' => 'H1', 'text_type' => 'Manual', 'text' => '',
    'patterns' => '', 'pattern_format' => 'lines', 'algorithm' => 'naive', 'runs' => '1', 'warmups' => '2',
    'source_reference' => '', 'seed' => ''];
$error = null;
$id = is_string($_GET['session'] ?? null) ? $_GET['session'] : null;
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
try {
    if (isset($_GET['clear_prepared'])) {
        unset($_SESSION['prepared_dataset_input']);
        $inputs = array_replace($inputs, ['text_type' => 'Manual', 'text' => '', 'patterns' => '', 'pattern_format' => 'lines',
            'seed' => '', 'source_reference' => '', 'runs' => '1', 'name' => 'Manual test']);
        $_SESSION['manual_inputs_v2'] = $inputs;
    }
    if (isset($_GET['dataset'])) {
        if (!is_string($_GET['dataset']) || !is_string($_GET['group'] ?? null)) {
            throw new InvalidArgumentException('Choose a saved dataset and group.');
        }
        $library = new \StringMatching\DatasetLibrary(getenv('STRING_MATCHING_DATASET_DIR') ?: dirname(__DIR__) . '/experiment-results/datasets');
        $prepared = $library->input($_GET['dataset'], $_GET['group']);
        $inputs = array_replace($inputs, [
            'group_id' => 'demo-' . substr(hash('sha256', $prepared['group_id']), 0, 12) . '-' . bin2hex(random_bytes(4)), 'name' => $prepared['name'], 'hypothesis' => $prepared['hypothesis'] ?: 'H1',
            'text_type' => $prepared['text_type'], 'text' => $prepared['text'],
            'patterns' => array_filter($prepared['patterns'], static fn(string $pattern): bool => str_contains($pattern, "\n") || str_contains($pattern, "\r"))
                ? json_encode($prepared['patterns'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : implode("\n", $prepared['patterns']),
            'pattern_format' => array_filter($prepared['patterns'], static fn(string $pattern): bool => str_contains($pattern, "\n") || str_contains($pattern, "\r")) ? 'json' : 'lines', 'runs' => (string) $prepared['runs'], 'warmups' => (string) $prepared['warmups'],
            'source_reference' => $prepared['source_reference'], 'seed' => $prepared['seed'],
        ]);
        $_SESSION['manual_inputs_v2'] = $inputs;
        $_SESSION['prepared_dataset_input'] = $prepared;
        $notice = 'Saved dataset loaded. Choose an algorithm and run count; no measurement has started.';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrf();
        $action = posted('action');
        if (in_array($action, ['run', 'compare'], true)) {
            foreach (array_keys($inputs) as $key) {
                $inputs[$key] = posted($key);
            }
            $_SESSION['manual_inputs_v2'] = $inputs;
            $patterns = match ($inputs['pattern_format']) {
                'lines' => preg_split('/\r\n|\r|\n/', $inputs['patterns']),
                'json' => json_decode($inputs['patterns'], true, 512, JSON_THROW_ON_ERROR),
                default => throw new InvalidArgumentException('Select a pattern input format.'),
            };
            if (trim($inputs['name']) === '') {
                throw new InvalidArgumentException('Supply an experiment name.');
            }
            if (!is_array($patterns)) {
                throw new InvalidArgumentException('Patterns must be a list.');
            }
            $input = Workload::validate(array_replace($inputs, ['patterns' => $patterns]));
            $prepared = $_SESSION['prepared_dataset_input'] ?? null;
            if ($prepared !== null) {
                foreach (['text', 'patterns', 'text_type', 'seed', 'source_reference'] as $field) {
                    if ($input[$field] !== $prepared[$field]) {
                        throw new InvalidArgumentException('The loaded dataset input was changed. Reload it or choose Start a new manual input before editing its source contents.');
                    }
                }
                foreach (['source_sha256', 'source_offset', 'normalization', 'generation_method', 'sample_id', 'dataset_metadata_json'] as $field) {
                    $input[$field] = $prepared[$field];
                }
                $input = Workload::validate($input);
            }
            $algorithms = $action === 'compare' ? array_keys(ExperimentEngine::ALGORITHMS) : [$inputs['algorithm']];
            $plan = $repository->create([$input], $algorithms, 'manual');
            $id = $plan['session_id'];
            $_SESSION['current_manual_session'] = $id;
            $repository->confirm($id, $action === 'compare' ? 'manual Compare Algorithms button' : 'manual Run Experiment button');
            set_time_limit(0);
            (new SessionRunner($repository))->run($id);
            $repository->markSaved($id);
            $_SESSION['notice'] = 'Experiment completed. Your results are saved and ready to download.';
            go('./?session=' . $id);
        } elseif ($action === 'save') {
            $id = posted('session_id');
            (new ResearchExporter($repository))->refresh($id);
            $repository->markSaved($id);
            $_SESSION['notice'] = 'Results saved. Repeating Save Results does not add measured rows.';
            go('./?session=' . $id);
        } elseif ($action === 'observe') {
            $id = posted('session_id');
            $repository->observe($id, posted('observation'));
            $_SESSION['notice'] = 'Research observation saved. No experiment was run.';
            go('./?session=' . $id);
        } else {
            throw new InvalidArgumentException('Unknown experiment action.');
        }
    }
    if ($id !== null) {
        $repository->plan($id);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $inputs['group_id'] = 'manual-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $inputs['name'] = trim($inputs['name']) === '' ? 'Manual test' : $inputs['name'];
}
pageStart('Manual Test', 'manual');
?>
<p>Try one input with a chosen algorithm, or compare all three algorithms.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if ($notice): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif ?>
<?php if (isset($_SESSION['prepared_dataset_input'])): ?><p class="notice">A generated input is loaded. Choose an algorithm and the number of runs. <a href="./?clear_prepared=1">Start a new manual input</a></p><?php endif ?>
<section><h2>Run a manual test</h2>
<form class="manual-grid" method="post" action="./?manual=1">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">
<?php foreach ($inputs as $key => $value): if (in_array($key, ['text', 'patterns', 'algorithm', 'runs'], true)) { continue; } ?>
<input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>">
<?php endforeach ?>
<div class="field wide"><label for="text">Text to search</label><textarea id="text" name="text" rows="5" placeholder="For example: ABABA" required <?php if (isset($_SESSION['prepared_dataset_input'])): ?>readonly<?php endif ?>><?php echo "\n", h($inputs['text']); ?></textarea></div>
<div class="field"><label for="patterns">Patterns to find</label><textarea id="patterns" name="patterns" rows="5" placeholder="One pattern per line" required <?php if (isset($_SESSION['prepared_dataset_input'])): ?>readonly<?php endif ?>><?php echo "\n", h($inputs['patterns']); ?></textarea></div>
<div class="field"><label for="algorithm">Algorithm</label><select id="algorithm" name="algorithm"><?php foreach (ExperimentEngine::ALGORITHMS as $key => $label): ?><option value="<?= h($key) ?>" <?= $inputs['algorithm'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach ?></select></div>
<div class="field"><label for="runs">Number of runs</label><input id="runs" name="runs" type="number" min="1" max="100000" step="1" list="run-presets" value="<?= h($inputs['runs']) ?>" required><datalist id="run-presets"><option value="1"><option value="30"><option value="10000"></datalist></div>
<div class="actions"><button name="action" value="run">Run Experiment</button> <button class="secondary" name="action" value="compare">Compare All Three</button></div>
</form></section>
<?php if ($id !== null): ?>
<?php try { $plan = $repository->plan($id); $status = $repository->status($id); ?>
<p class="notice"><?= h(runStatus($status['state'])) ?> · <?= (int) $status['completed'] ?> / <?= (int) $plan['planned_measurements'] ?> measurements saved. <?= h($status['error'] ?? '') ?></p>
<?php if (is_file($repository->path($id) . '/summary.csv')): results($repository, $id); endif ?>
<details><summary>Add a research note</summary>
<form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="session_id" value="<?= h($id) ?>"><label for="observation">Your note</label><textarea id="observation" name="observation" rows="4"><?php echo "\n", h(is_file($repository->path($id) . '/observations.md') ? file_get_contents($repository->path($id) . '/observations.md') : ''); ?></textarea><button name="action" value="observe">Save Note</button></form>
</details>
<?php } catch (Throwable $exception) { echo '<p class="notice error">' . h($exception->getMessage()) . '</p>'; } ?>
<?php endif ?>
<?php $previous = array_filter($repository->sessions(), static fn(array $session): bool => $session['mode'] === 'manual'); if ($previous !== []): ?>
<details><summary>Previous manual tests</summary><ul>
<?php foreach ($previous as $session): ?><li><a href="./?session=<?= h($session['session_id']) ?>"><?= h(implode(', ', array_map(static fn(string $algorithm): string => ExperimentEngine::ALGORITHMS[$algorithm], $session['algorithms'])) . ' · ' . $session['created_at']) ?></a></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<?php session_write_close(); ?>
</main></body></html>