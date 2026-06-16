<?php

declare(strict_types=1);

namespace App\Support;

final class Rank
{
    /** @var list<string> */
    public const LEVELS = ['C-', 'C', 'C+', 'B-', 'B', 'B+', 'A-', 'A', 'A+'];

    public static function isValid(string $rank): bool
    {
        return in_array($rank, self::LEVELS, true);
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
}
