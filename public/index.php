<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$autoload = $basePath . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Setup Required</title></head><body style="font-family:system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem;">';
    echo '<h1>Setup diperlukan</h1>';
    echo '<p>Folder <code>vendor/</code> belum ada. Di Terminal hPanel, jalankan:</p>';
    echo '<pre style="background:#f4f7fb;padding:1rem;border-radius:0.5rem;">cd ~/domains/skyblue-donkey-768625.hostingersite.com/public_html
composer install --no-dev --optimize-autoloader</pre>';
    echo '</body></html>';
    exit;
}

require_once $autoload;

use App\Application;

try {
    (new Application())->run();
} catch (Throwable $exception) {
    $debug = false;
    $envFile = $basePath . '/.env';

    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), 'APP_DEBUG=')) {
                $debug = filter_var(trim(substr($line, 10), " \t\"'"), FILTER_VALIDATE_BOOLEAN);
                break;
            }
        }
    }

    error_log('[faithsmasher] ' . $exception->getMessage());

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Error</title></head><body style="font-family:system-ui,sans-serif;max-width:36rem;margin:3rem auto;padding:0 1rem;">';
    echo '<h1>Aplikasi belum siap</h1>';
    echo '<p>Periksa <code>.env</code>, koneksi database, dan import <code>database/schema.sql</code>.</p>';

    if ($debug) {
        echo '<pre style="background:#fef2f2;padding:1rem;border-radius:0.5rem;overflow:auto;">'
            . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
            . '</pre>';
    }

    echo '</body></html>';
}
