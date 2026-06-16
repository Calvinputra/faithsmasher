<?php

declare(strict_types=1);

/**
 * Smoke-test authenticated GET routes. Usage: php bin/smoke-routes.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$base = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
$email = $argv[1] ?? 'calvin@gmail.com';
$password = $argv[2] ?? 'Calvinpn99*';

$cookie = tempnam(sys_get_temp_dir(), 'fs_cookie');

function request(string $url, string $cookie, string $method = 'GET', ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $body !== null ? ['Content-Type: application/x-www-form-urlencoded'] : [],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $parts = explode("\r\n\r\n", $raw, 2);
    $headers = $parts[0] ?? '';
    $html = $parts[1] ?? '';

    return ['code' => $code, 'headers' => $headers, 'html' => $html];
}

function login(string $base, string $cookie, string $email, string $password): bool
{
    request("{$base}/login", $cookie);
    $res = request("{$base}/login", $cookie, 'POST', http_build_query([
        'email' => $email,
        'password' => $password,
    ]));

    if ($res['code'] === 302 && str_contains($res['headers'], 'Location: /dashboard')) {
        return true;
    }

    if ($res['code'] === 302) {
        preg_match('/Location: (.+)/', $res['headers'], $m);

        return str_contains($m[1] ?? '', '/dashboard');
    }

    return false;
}

function extractSessionId(string $html): ?int
{
    if (preg_match('/href="\/dashboard\/(\d+)"/', $html, $m)) {
        return (int) $m[1];
    }

    if (preg_match('/href="\/sessions\/(\d+)\/edit"/', $html, $m)) {
        return (int) $m[1];
    }

    return null;
}

function checkPage(string $label, string $url, string $cookie): array
{
    $res = request($url, $cookie);
    $issues = [];

    if ($res['code'] >= 500) {
        $issues[] = "HTTP {$res['code']}";
    }

    if (str_contains($res['html'], 'Something went wrong')) {
        $issues[] = 'Error page rendered';
    }

    if (preg_match('/Unknown "[^"]+" (function|filter)/', $res['html'], $m)) {
        $issues[] = $m[0];
    }

    if (preg_match('/(Fatal error|Parse error|Uncaught|SQLSTATE\[)/', $res['html'], $m)) {
        $issues[] = trim($m[0]);
    }

    if (preg_match('/<pre class="mt-3[^"]*">([^<]+)/', $res['html'], $m)) {
        $issues[] = trim($m[1]);
    }

    return [
        'label' => $label,
        'url' => $url,
        'code' => $res['code'],
        'issues' => $issues,
    ];
}

if (!login($base, $cookie, $email, $password)) {
    fwrite(STDERR, "Login failed for {$email}\n");
    exit(1);
}

$dashboard = request("{$base}/dashboard", $cookie);
$sessionId = extractSessionId($dashboard['html']);

$routes = [
    ['Home (public)', "{$base}/", false],
    ['Dashboard', "{$base}/dashboard", true],
    ['Participants global', "{$base}/participants", true],
    ['Participants create', "{$base}/participants/create", true],
    ['Participants bulk', "{$base}/participants/bulk", true],
    ['Session create', "{$base}/sessions/create", true],
    ['Admin users', "{$base}/admin/users", true],
];

if ($sessionId !== null) {
    $routes = array_merge($routes, [
        ['Session overview', "{$base}/dashboard/{$sessionId}", true],
        ['Session edit', "{$base}/sessions/{$sessionId}/edit", true],
        ['Session participants', "{$base}/sessions/{$sessionId}/participants", true],
        ['Session participants assign', "{$base}/sessions/{$sessionId}/participants/assign", true],
        ['Session participants bulk', "{$base}/sessions/{$sessionId}/participants/bulk", true],
        ['Game rules', "{$base}/sessions/{$sessionId}/rules", true],
        ['Game rules create', "{$base}/sessions/{$sessionId}/rules/create", true],
        ['Matches index', "{$base}/sessions/{$sessionId}/matches", true],
        ['Matches manual', "{$base}/sessions/{$sessionId}/matches/manual", true],
        ['Matches preview', "{$base}/sessions/{$sessionId}/matches/preview", true],
        ['Matches counter', "{$base}/sessions/{$sessionId}/matches/counter", true],
    ]);
}

$failed = 0;

foreach ($routes as [$label, $url, $auth]) {
    if (!$auth) {
        $res = checkPage($label, $url, tempnam(sys_get_temp_dir(), 'fs_pub'));
    } else {
        $res = checkPage($label, $url, $cookie);
    }

    if ($res['issues'] !== []) {
        $failed++;
        echo "FAIL [{$res['code']}] {$label}\n";
        echo "  URL: {$url}\n";
        foreach ($res['issues'] as $issue) {
            echo "  → {$issue}\n";
        }
    } else {
        echo "OK   [{$res['code']}] {$label}\n";
    }
}

@unlink($cookie);

echo "\n{$failed} route(s) with issues.\n";
exit($failed > 0 ? 1 : 0);
