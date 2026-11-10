<?php
declare(strict_types=1);
require __DIR__ . '/_common.php';

try {
    $id = is_string($_GET['session'] ?? null) ? $_GET['session'] : '';
    $file = is_string($_GET['file'] ?? null) ? $_GET['file'] : '';
    $allowed = ['source-inputs.csv', 'runs.csv', 'summary.csv', 'experiment-manifest.json', 'observations.md'];
    if (!in_array($file, $allowed, true)) {
        throw new InvalidArgumentException('Unknown research export.');
    }
    $repository->plan($id);
    $path = $repository->path($id) . '/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException('This export is not available yet.');
    }
    session_write_close();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
} catch (Throwable $exception) {
    http_response_code(404);
    echo h($exception->getMessage());
}