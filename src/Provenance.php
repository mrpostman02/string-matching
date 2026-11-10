<?php
/**
 * @author John Eddie Macias <john_eddie_macias@dlsu.edu.ph>
 */
declare(strict_types=1);

namespace StringMatching;

final class Provenance
{
    /** hash the source files so experiments have a code version even without git */
    public static function sourceVersion(): array
    {
        $root = dirname(__DIR__);
        $files = ['bootstrap.php' => hash_file('sha256', $root . '/bootstrap.php')];
        foreach (['src', 'config', 'public'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory,
                \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'css', 'js', 'json'], true)) {
                    $name = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $files[$name] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($files, SORT_STRING);
        return ['method' => 'sha256-sorted-source-file-map', 'sha256' => hash('sha256',
            json_encode($files, JSON_THROW_ON_ERROR)), 'files' => $files];
    }

    public static function runtime(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);
        $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
        $jit = is_array($opcache) ? ($opcache['jit'] ?? []) : [];
        unset($jit['buffer_free']);
        return [
            'opcache_active' => is_array($opcache) && !empty($opcache['opcache_enabled']),
            'jit_runtime_status' => $jit,
            'php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'sapi' => PHP_SAPI,
            'enabled_extensions' => $extensions, 'os' => PHP_OS_FAMILY, 'uname' => php_uname(),
            'machine' => gethostname(), 'php_int_size' => PHP_INT_SIZE,
            'opcache_enabled' => ini_get('opcache.enable'), 'opcache_cli_enabled' => ini_get('opcache.enable_cli'),
            'opcache_loaded' => extension_loaded('Zend OPcache'),
            'jit' => ini_get('opcache.jit'), 'jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
            'xdebug_loaded' => extension_loaded('xdebug'), 'xdebug_mode' => ini_get('xdebug.mode'),
            'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
            'loaded_ini' => php_ini_loaded_file(), 'scanned_ini' => php_ini_scanned_files(),
        ];
    }
}