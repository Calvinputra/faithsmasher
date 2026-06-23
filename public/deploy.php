<?php

declare(strict_types=1);

// Prevent caching
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Type: application/json');

// Get secret from .env
$basePath = dirname(__DIR__);
$envFile = $basePath . '/.env';
$deploySecret = null;

if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), 'DEPLOY_SECRET=')) {
            $deploySecret = trim(substr(trim($line), 14), " \t\"'");
            break;
        }
    }
}

// Verify GitHub webhook signature if DEPLOY_SECRET is set
if ($deploySecret) {
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!$signature) {
        http_response_code(403);
        echo json_encode(['error' => 'Missing signature']);
        exit;
    }

    $payload = file_get_contents('php://input');
    $hash = 'sha256=' . hash_hmac('sha256', $payload, $deploySecret);

    if (!hash_equals($hash, $signature)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

// Ensure the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST request.']);
    exit;
}

// Check if it's the right branch (default to main)
$payload = json_decode(file_get_contents('php://input'), true);
if (isset($payload['ref']) && $payload['ref'] !== 'refs/heads/main') {
    echo json_encode(['success' => true, 'message' => 'Skipped deployment. Not the main branch.']);
    exit;
}

// Execute deployment commands
$output = [];
$commands = [
    'git fetch origin main 2>&1',
    'git reset --hard origin/main 2>&1', // Ensure strict sync with remote
    'export COMPOSER_HOME=/tmp/composer; composer install --no-dev --optimize-autoloader 2>&1',
];

$output[] = "Starting deployment at " . date('Y-m-d H:i:s');

foreach ($commands as $command) {
    $output[] = "==================================================";
    $output[] = "$ $command";
    $result = shell_exec("cd {$basePath} && {$command}");
    $output[] = $result !== null ? trim($result) : "No output";
}

$output[] = "==================================================";
$output[] = "Deployment finished at " . date('Y-m-d H:i:s');

// Make sure storage/logs directory exists
$logDir = $basePath . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Save log
file_put_contents($logDir . '/deploy.log', implode("\n", $output) . "\n", FILE_APPEND);

echo json_encode([
    'success' => true,
    'message' => 'Deployed successfully',
    'details' => 'Check storage/logs/deploy.log for more info'
]);
