#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Buat superadmin pertama (jika belum ada user).
 *
 * Usage:
 *   php bin/create-admin.php "Calvin" calvin@gmail.com "password-anda"
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\Connection;
use Dotenv\Dotenv;

$basePath = dirname(__DIR__);
$envFile = $basePath . '/.env';

if (is_file($envFile)) {
    Dotenv::createImmutable($basePath)->safeLoad();
}

$name = $argv[1] ?? null;
$email = isset($argv[2]) ? strtolower(trim($argv[2])) : null;
$password = $argv[3] ?? null;

if ($name === null || $email === null || $password === null || $password === '') {
    fwrite(STDERR, "Usage: php bin/create-admin.php \"Nama\" email@example.com \"password\"\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Email tidak valid.\n");
    exit(1);
}

try {
    $pdo = Connection::get();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Koneksi database gagal: ' . $exception->getMessage() . "\n");
    fwrite(STDERR, "Pastikan .env benar dan schema sudah di-import (database/schema.sql).\n");
    exit(1);
}

$tables = $pdo->query('SHOW TABLES LIKE "users"')->fetchAll();

if ($tables === []) {
    fwrite(STDERR, "Tabel users belum ada. Import dulu: database/schema.sql via phpMyAdmin atau mysql CLI.\n");
    exit(1);
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($count > 0) {
    fwrite(STDERR, "Sudah ada {$count} user. Script ini hanya untuk setup awal (database kosong).\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$statement = $pdo->prepare(
    'INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, :status)'
);
$statement->execute([
    'name' => trim($name),
    'email' => $email,
    'password' => $hash,
    'role' => 'superadmin',
    'status' => 'approved',
]);

fwrite(STDOUT, "Superadmin berhasil dibuat.\n");
fwrite(STDOUT, "Email: {$email}\n");
fwrite(STDOUT, "Role: superadmin · Status: approved\n");
fwrite(STDOUT, "Silakan login di /login\n");
