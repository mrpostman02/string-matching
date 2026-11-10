<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class WorkerLauncher
{
    public static function active(SessionRepository $repository, string $id): bool
    {
        $handle = fopen($repository->path($id) . '/worker.lock', 'c+b');
        if (!$handle) {
            throw new \RuntimeException('Cannot inspect the worker lock.');
        }
        try {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                flock($handle, LOCK_UN);
                return false;
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    /** detach the worker so closing the browser does not interrupt the experiment */
    public static function launch(SessionRepository $repository, string $id): void
    {
        $directory = $repository->path($id);
        $repository->plan($id);
        if (!is_file($directory . '/confirmation.json')) {
            throw new \RuntimeException('Confirm the preview before starting a worker.');
        }
        if (self::active($repository, $id)) {
            throw new \RuntimeException('This session already has an active worker.');
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('Background experiment execution requires proc_open in PHP.');
        }
        $root = dirname(__DIR__);
        $binary = getenv('STRING_MATCHING_PHP_CLI') ?: (PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli' ? PHP_BINARY
            : PHP_BINDIR . (PHP_OS_FAMILY === 'Windows' ? '/php.exe' : '/php'));
        if (!is_file($binary)) {
            throw new \RuntimeException('PHP CLI executable not found. Set STRING_MATCHING_PHP_CLI to the full path of PHP CLI.');
        }
        $stdout = $directory . '/worker.stdout.log';
        $stderr = $directory . '/worker.stderr.log';
        $suffix = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        // use separate logs for each launch so a retry does not erase the previous failure
        $stdout = $directory . '/worker-' . $suffix . '.stdout.log';
        $stderr = $directory . '/worker-' . $suffix . '.stderr.log';
        if (PHP_OS_FAMILY === 'Windows') {
            $quote = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
            $arguments = '-f "' . $root . '/src/worker.php" -- --session=' . $id . ' --data-root="' . $repository->root() . '"';
            $script = '$ErrorActionPreference = ' . $quote('Stop') . '; Start-Process -FilePath ' . $quote($binary)
                . ' -ArgumentList ' . $quote($arguments) . ' -WorkingDirectory ' . $quote($root)
                . ' -WindowStyle Hidden -RedirectStandardOutput ' . $quote($stdout)
                . ' -RedirectStandardError ' . $quote($stderr);
            $encoded = iconv('UTF-8', 'UTF-16LE', $script);
            if ($encoded === false) {
                throw new \RuntimeException('Cannot encode the worker launch command.');
            }
            $command = ['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', base64_encode($encoded)];
        } else {
            $commandText = 'nohup ' . escapeshellarg($binary) . ' ' . escapeshellarg($root . '/src/worker.php')
                . ' ' . escapeshellarg('--session=' . $id) . ' ' . escapeshellarg('--data-root=' . $repository->root())
                . ' >' . escapeshellarg($stdout) . ' 2>' . escapeshellarg($stderr) . ' </dev/null &';
            $command = ['/bin/sh', '-c', $commandText];
        }
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the PHP experiment worker.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $repository->event($id, ['type' => 'worker_launch', 'exit_code' => $code, 'launcher_output' => $output,
            'launcher_error' => $error, 'stdout_log' => basename($stdout), 'stderr_log' => basename($stderr)]);
        if ($code !== 0) {
            throw new \RuntimeException('The worker could not be launched: ' . $error . '');
        }
    }
}