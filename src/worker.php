<?php
/**
 * Execute a confirmed experiment session outside the web request.
 *
 * @package StringMatching
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use StringMatching\{SessionRepository, SessionRunner};

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

try {
    $options = getopt('', ['session:', 'data-root:']);
    if (!is_string($options['session'] ?? null)) {
        throw new InvalidArgumentException('A confirmed experiment session is required.');
    }
    $dataRoot = $options['data-root'] ?? (getenv('STRING_MATCHING_DATA_DIR') ?: dirname(__DIR__) . '/experiment-results/sessions');
    if (!is_string($dataRoot) || $dataRoot === '') {
        throw new InvalidArgumentException('A valid experiment storage directory is required.');
    }
    $repository = new SessionRepository($dataRoot);
    set_time_limit(0);
    $status = (new SessionRunner($repository))->run($options['session']);
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit(in_array($status['state'], ['complete_with_failures', 'failed'], true) ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
