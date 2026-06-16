<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$pdo = App\Database\Connection::get();

echo '=== DB: ' . ($_ENV['DB_HOST'] ?? '') . ' / ' . ($_ENV['DB_NAME'] ?? '') . PHP_EOL . PHP_EOL;

echo "=== USERS ===\n";
foreach ($pdo->query('SELECT id, name, email, role, status FROM users ORDER BY id') as $row) {
    printf("#%d %s (%s/%s) - %s\n", $row['id'], $row['email'], $row['role'], $row['status'], $row['name']);
}

echo "\n=== SESSIONS ===\n";
foreach ($pdo->query(
    'SELECT s.id, s.user_id, u.email, s.name, s.session_date
     FROM sessions s JOIN users u ON u.id = s.user_id ORDER BY s.id'
) as $row) {
    printf("#%d user=%s name=%s date=%s\n", $row['id'], $row['email'], $row['name'], $row['session_date']);
}

echo "\n=== PARTICIPANTS (global) ===\n";
$count = (int) $pdo->query('SELECT COUNT(*) FROM participants')->fetchColumn();
foreach ($pdo->query(
    'SELECT p.id, p.user_id, u.email, p.name
     FROM participants p JOIN users u ON u.id = p.user_id ORDER BY p.id LIMIT 20'
) as $row) {
    printf("#%d user=%s name=%s\n", $row['id'], $row['email'], $row['name']);
}
echo "Total participants: {$count}\n";
