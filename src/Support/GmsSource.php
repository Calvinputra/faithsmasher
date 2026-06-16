<?php

declare(strict_types=1);

namespace App\Support;

final class GmsSource
{
    /** @var list<string> */
    public const OPTIONS = ['GMS', 'VIP', 'CP', 'PURI', 'PLUIT', 'GANCIT', 'ALSUT'];

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::OPTIONS as $option) {
            $options[$option] = $option;
        }

        return $options;
    }

    public static function isValid(?string $source): bool
    {
        return $source === null || $source === '' || in_array($source, self::OPTIONS, true);
    }

    public static function normalize(?string $source): ?string
    {
        if ($source === null || trim($source) === '') {
            return null;
        }

        $source = strtoupper(trim($source));

        return in_array($source, self::OPTIONS, true) ? $source : null;
    }

    public static function pillClass(?string $source): string
    {
        return match ($source) {
            'GMS' => 'inline-pill-gms',
            'VIP' => 'inline-pill-vip',
            'CP' => 'inline-pill-cp',
            'PURI' => 'inline-pill-puri',
            'PLUIT' => 'inline-pill-pluit',
            'GANCIT' => 'inline-pill-gancit',
            'ALSUT' => 'inline-pill-alsut',
            default => 'inline-pill-empty',
        };
    }
}
