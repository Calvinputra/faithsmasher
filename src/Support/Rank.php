<?php

declare(strict_types=1);

namespace App\Support;

final class Rank
{
    /** @var list<string> */
    public const LEVELS = ['C-', 'C', 'C+', 'B-', 'B', 'B+', 'A-', 'A', 'A+'];

    public static function isValid(string $rank): bool
    {
        return self::normalize($rank) !== null;
    }

    public static function normalize(string $rank): ?string
    {
        $rank = trim($rank);

        if ($rank === '') {
            return null;
        }

        foreach (self::LEVELS as $level) {
            if (strcasecmp($rank, $level) === 0) {
                return $level;
            }
        }

        return null;
    }

    public static function index(string $rank): int
    {
        $index = array_search($rank, self::LEVELS, true);

        return $index === false ? 0 : (int) $index;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::LEVELS as $level) {
            $options[$level] = $level;
        }

        return $options;
    }

    public static function pillClass(string $rank): string
    {
        return 'inline-pill-rank';
    }
}
