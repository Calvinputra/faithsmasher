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

    /** @return array<string, string> */
    public static function inlineLabels(): array
    {
        return [
            'male' => 'Male',
            'female' => 'Female',
        ];
    }

    public static function pillClass(?string $gender): string
    {
        return match ($gender) {
            'male' => 'inline-pill-male',
            'female' => 'inline-pill-female',
            default => 'inline-pill-empty',
        };
    }

    public static function label(?string $gender): string
    {
        if ($gender === null || $gender === '') {
            return '—';
        }

        return self::labels()[$gender] ?? $gender;
    }

    public static function normalize(?string $gender): ?string
    {
        if ($gender === null || trim($gender) === '') {
            return null;
        }

        $value = strtolower(trim($gender));

        if (in_array($value, self::OPTIONS, true)) {
            return $value;
        }

        return match (true) {
            in_array($value, ['m', 'l', 'laki', 'laki-laki', 'pria', 'cowok', 'male'], true) => 'male',
            in_array($value, ['f', 'p', 'perempuan', 'wanita', 'cewek', 'female'], true) => 'female',
            in_array($value, ['other', 'lain', 'lainnya', 'o'], true) => 'other',
            default => null,
        };
    }

    /** @return list<string> */
    public static function acceptedInputExamples(): array
    {
        return ['Male', 'Female', 'male', 'female', 'M', 'F', 'Pria', 'Wanita'];
    }
}
