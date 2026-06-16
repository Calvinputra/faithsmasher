<?php

declare(strict_types=1);

namespace App\Support;

final class FlashType
{
    public const SUCCESS = 'success';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    public static function title(string $type): string
    {
        return match ($type) {
            self::CREATE => 'Berhasil dibuat',
            self::UPDATE => 'Berhasil diperbarui',
            self::DELETE => 'Berhasil dihapus',
            self::ERROR => 'Terjadi kesalahan',
            self::WARNING => 'Perhatian',
            self::INFO => 'Informasi',
            default => 'Berhasil',
        };
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, [
            self::SUCCESS,
            self::ERROR,
            self::WARNING,
            self::INFO,
            self::CREATE,
            self::UPDATE,
            self::DELETE,
        ], true);
    }
}
