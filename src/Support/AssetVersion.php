<?php

declare(strict_types=1);

namespace App\Support;

final class AssetVersion
{
    public static function url(string $basePath, string $path): string
    {
        $normalized = ltrim($path, '/');
        $file = rtrim($basePath, '/') . '/public/' . $normalized;
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return '/' . $normalized . '?v=' . $version;
    }
}
