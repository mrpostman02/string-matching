<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class AtomicFiles
{
    private const STATUS_RETRY_DELAYS_US = [25000, 50000, 100000, 200000, 400000];
    private const STATUS_RETRY_DEADLINE_NS = 1000000000;

    /** Flush a sibling file before publication; retain it if publication fails. */
    public static function write(string $path, string $contents, bool $retryWindowsStatusRename = false): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot create $temporary.");
        }
        try {
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Cannot write $path; check disk space.");
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException("Cannot flush $path.");
            }
        } finally {
            fclose($handle);
        }
        if ($retryWindowsStatusRename && PHP_OS_FAMILY === 'Windows' && basename($path) === 'status.json') {
            self::publishStatus($temporary, $path);
            return;
        }
        if (!rename($temporary, $path)) {
            throw new \RuntimeException("Cannot publish $path. Close it in Excel and retry. The temporary file is preserved.");
        }
    }

    /** Retry Windows status replacement using the same flushed file. */
    private static function publishStatus(string $temporary, string $path): void
    {
        $started = hrtime(true);
        $attempts = 0;
        $context = ['path' => $path, 'temporary' => $temporary, 'native_code' => null, 'warning' => ''];
        while (true) {
            ++$attempts;
            $warning = '';
            set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
                if ($severity !== E_WARNING || !str_starts_with($message, 'rename(')) {
                    return false;
                }
                $warning = $message;
                return true;
            });
            try {
                $published = rename($temporary, $path);
            } finally {
                restore_error_handler();
            }
            $context['attempts'] = $attempts;
            $context['elapsed_ms'] = (hrtime(true) - $started) / 1000000;
            if ($published) {
                if ($attempts > 1) {
                    self::logStatusPublication('atomic_status_publish_recovered', $context);
                }
                return;
            }
            $context['warning'] = $warning;
            $context['native_code'] = preg_match('/\(code:\s*(\d+)\)\s*$/', $warning, $match) === 1
                ? (int) $match[1] : null;
            $delay = self::STATUS_RETRY_DELAYS_US[$attempts - 1] ?? null;
            $parent = dirname($path);
            clearstatcache(true, $path);
            clearstatcache(true, $temporary);
            clearstatcache(true, $parent);
            // Code 5 is ambiguous: a deny-delete handle and permanent access denial can both produce it.
            if ($delay === null || !in_array($context['native_code'], [5, 32, 33], true)
                || !is_file($path) || !is_file($temporary) || !is_dir($parent)
                || !is_writable($path) || !is_writable($temporary) || !is_writable($parent)
                || hrtime(true) - $started + $delay * 1000 >= self::STATUS_RETRY_DEADLINE_NS) {
                break;
            }
            self::logStatusPublication('atomic_status_publish_retry', $context + ['delay_ms' => $delay / 1000]);
            usleep($delay);
            if (hrtime(true) - $started >= self::STATUS_RETRY_DEADLINE_NS) {
                break;
            }
        }
        $context['elapsed_ms'] = (hrtime(true) - $started) / 1000000;
        self::logStatusPublication('atomic_status_publish_failed', $context);
        throw new \RuntimeException(sprintf(
            'Cannot publish %s after %d attempt(s) in %.1f ms. Temporary file preserved: %s. '
            . 'The journal is authoritative. Release the file holder or correct permissions, then resume the same '
            . 'confirmed session with the same source/runtime. Rename diagnostic: %s',
            $path, $attempts, $context['elapsed_ms'], $temporary, $warning
        ));
    }

    /** Keep publication diagnostics outside measured intervals and the journal. */
    private static function logStatusPublication(string $event, array $context): void
    {
        error_log(json_encode(['event' => $event] + $context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public static function json(string $path, array $value, bool $retryWindowsStatusRename = false): void
    {
        self::write($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
            $retryWindowsStatusRename);
    }

    public static function read(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read $path.");
        }
        $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException("Invalid JSON object in $path.");
        }
        return $value;
    }
}
