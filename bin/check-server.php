#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cek kesiapan server Hostinger (jalankan via hPanel Terminal).
 *
 * Usage: php bin/check-server.php
 */

$basePath = dirname(__DIR__);
$checks = [];

$checks[] = check('PHP >= 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);

$vendor = is_file($basePath . '/vendor/autoload.php');
$checks[] = check('vendor/autoload.php', $vendor, $vendor ? 'OK' : 'Jalankan: composer install --no-dev');

$envFile = $basePath . '/.env';
$envExists = is_file($envFile);
$checks[] = check('.env ada', $envExists, $envExists ? $envFile : 'Copy dari .env.hostinger.example');

$dbOk = false;
$dbDetail = 'Lewati — .env belum ada';

if ($envExists) {
    require $basePath . '/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable($basePath)->safeLoad();

    try {
        $pdo = App\Database\Connection::get();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $dbOk = $tables !== [];
        $dbDetail = $dbOk
            ? count($tables) . ' tabel: ' . implode(', ', $tables)
            : 'Terhubung tapi kosong — import database/schema.sql';
    } catch (Throwable $exception) {
        $dbDetail = $exception->getMessage();
    }
}

$checks[] = check('Database', $dbOk, $dbDetail);

$storageWritable = is_writable($basePath . '/storage/logs')
    && is_writable($basePath . '/storage/cache');
$checks[] = check('storage/ writable', $storageWritable, $storageWritable ? 'OK' : 'chmod 755 storage/');

echo "Faith Smashers — Server Check\n";
echo str_repeat('=', 40) . "\n";

$failed = 0;

foreach ($checks as $item) {
    $icon = $item['ok'] ? '✓' : '✗';
    echo "{$icon} {$item['label']}\n   → {$item['detail']}\n";
    if (!$item['ok']) {
        $failed++;
    }
}

echo str_repeat('=', 40) . "\n";

if ($failed === 0) {
    echo "Semua OK. Buka situs di browser.\n";
    exit(0);
}

echo "{$failed} masalah perlu diperbaiki.\n";
exit(1);

/** @return array{ok: bool, label: string, detail: string} */
function check(string $label, bool $ok, string $detail): array
{
    return ['ok' => $ok, 'label' => $label, 'detail' => $detail];
}
