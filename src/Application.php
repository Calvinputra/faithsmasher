<?php

declare(strict_types=1);

namespace App;

final class Application
{
    public function run(): void
    {
        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Faith Smasher</title>
            <style>
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-family: system-ui, -apple-system, sans-serif;
                    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                    color: #f8fafc;
                }
                .card {
                    text-align: center;
                    padding: 3rem 2.5rem;
                    border-radius: 1rem;
                    background: rgba(255, 255, 255, 0.05);
                    border: 1px solid rgba(255, 255, 255, 0.1);
                    backdrop-filter: blur(10px);
                    max-width: 480px;
                }
                h1 { font-size: 2rem; margin-bottom: 0.75rem; }
                p { color: #94a3b8; line-height: 1.6; }
                .badge {
                    display: inline-block;
                    margin-top: 1.5rem;
                    padding: 0.35rem 0.85rem;
                    border-radius: 999px;
                    background: #22c55e;
                    color: #052e16;
                    font-size: 0.875rem;
                    font-weight: 600;
                }
            </style>
        </head>
        <body>
            <main class="card">
                <h1>Faith Smasher</h1>
                <p>Project PHP berhasil dibuat dan siap dikembangkan.</p>
                <span class="badge">PHP {$this->phpVersion()}</span>
            </main>
        </body>
        </html>
        HTML;
    }

    private function phpVersion(): string
    {
        return PHP_VERSION;
    }
}
