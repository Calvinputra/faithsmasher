<?php

declare(strict_types=1);

namespace App\Support;

final class BaganMatchGrouper
{
    /**
     * @param list<array{match: \App\Models\TournamentMatch, p1: ?array<string, mixed>, p2: ?array<string, mixed>}> $matchRows
     * @return array<int, list<array{match: \App\Models\TournamentMatch, p1: ?array<string, mixed>, p2: ?array<string, mixed>}>>
     */
    public static function byRound(array $matchRows): array
    {
        $matchesByBagan = [];

        foreach ($matchRows as $row) {
            $matchesByBagan[$row['match']->roundNumber][] = $row;
        }

        ksort($matchesByBagan);

        return $matchesByBagan;
    }
}
