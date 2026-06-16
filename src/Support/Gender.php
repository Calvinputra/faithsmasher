<?php

declare(strict_types=1);

namespace App\Support;

final class Gender
{
    /** @var list<string> */
    public const OPTIONS = ['male', 'female', 'other'];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'male' => 'Male',
            'female' => 'Female',
            'other' => 'Other',
        ];
    }

    public static function isValid(?string $gender): bool
    {
        return $gender === null || in_array($gender, self::OPTIONS, true);
    }

    public static function label(?string $gender): string
    {
        if ($gender === null || $gender === '') {
            return '—';
        }

        return self::labels()[$gender] ?? $gender;
    }
}
