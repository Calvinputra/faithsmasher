#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Import schema.sql ke database yang dikonfigurasi di .env
 *
 * Usage:
 *   php bin/setup-database.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\Connection;
use Dotenv\Dotenv;

$basePath = dirname(__DIR__);
$envFile = $basePath . '/.env';

if (is_file($envFile)) {
    Dotenv::createImmutable($basePath)->safeLoad();
}

$schemaFile = $basePath . '/database/schema.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "File schema tidak ditemukan: {$schemaFile}\n");
    exit(1);
}

try {
    $pdo = Connection::get();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Koneksi database gagal: ' . $exception->getMessage() . "\n");
    exit(1);
}

$sql = file_get_contents($schemaFile);

if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Schema file kosong.\n");
    exit(1);
}

$pdo->exec($sql);

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

fwrite(STDOUT, "Schema berhasil di-import.\n");
fwrite(STDOUT, 'Tabel: ' . implode(', ', $tables) . "\n");
fwrite(STDOUT, "\nLangkah berikutnya — buat superadmin pertama:\n");
fwrite(STDOUT, "  php bin/create-admin.php \"Nama Anda\" email@example.com \"password\"\n");
