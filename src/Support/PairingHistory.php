<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tracks partner (teman) and opponent (musuh) counts across bagan.
 * - Max teman: 1× per pasangan (A & B hanya sekali jadi partner)
 * - Max musuh: 2× per pasangan lawan
 */
final class PairingHistory
{
    private const MAX_PARTNERS = 1;

    private const MAX_OPPONENTS = 2;

    /** @var array<int, array<int, int>> */
    private array $partners = [];

    /** @var array<int, array<int, int>> */
    private array $opponents = [];

    /**
     * @param list<array{match: \App\Models\TournamentMatch, p1: ?array<string, mixed>, p2: ?array<string, mixed>}> $matchRows
     */
    public static function fromSessionMatches(array $matchRows): self
    {
        $history = new self();
        $grouped = [];

        foreach ($matchRows as $row) {
            $match = $row['match'];
            $key = $match->roundNumber . ':' . $match->matchOrder;
            $grouped[$key][] = $row;
        }

        foreach ($grouped as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $team1 = self::extractPlayerIds($rows[0]);
            $team2 = self::extractPlayerIds($rows[1]);

            if (count($team1) === 2 && count($team2) === 2) {
                $history->recordMatchup($team1[0], $team1[1], $team2[0], $team2[1]);
            }
        }

        return $history;
    }

    /** @param array{match: \App\Models\TournamentMatch, p1: ?array<string, mixed>, p2: ?array<string, mixed>} $row */
    private static function extractPlayerIds(array $row): array
    {
        $ids = [];
        $match = $row['match'];

        if ($match->participant1Id !== null) {
            $ids[] = $match->participant1Id;
        }

        if ($match->participant2Id !== null) {
            $ids[] = $match->participant2Id;
        }

        return $ids;
    }

    public function canPartner(int $playerA, int $playerB): bool
    {
        if ($playerA === $playerB) {
            return false;
        }

        return $this->partnerCount($playerA, $playerB) < self::MAX_PARTNERS;
    }

    public function canOppose(int $playerA, int $playerB): bool
    {
        if ($playerA === $playerB) {
            return false;
        }

        return $this->opponentCount($playerA, $playerB) < self::MAX_OPPONENTS;
    }

    /**
     * @param array{\App\Models\Participant, \App\Models\Participant} $team1
     * @param array{\App\Models\Participant, \App\Models\Participant} $team2
     */
    public function isValidDoublesMatch(array $team1, array $team2): bool
    {
        if (!$this->canPartner($team1[0]->id, $team1[1]->id)) {
            return false;
        }

        if (!$this->canPartner($team2[0]->id, $team2[1]->id)) {
            return false;
        }

        foreach ($team1 as $ally) {
            foreach ($team2 as $enemy) {
                if (!$this->canOppose($ally->id, $enemy->id)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function recordMatchup(int $t1p1, int $t1p2, int $t2p1, int $t2p2): void
    {
        $this->incrementPartner($t1p1, $t1p2);
        $this->incrementPartner($t2p1, $t2p2);

        foreach ([$t1p1, $t1p2] as $ally) {
            foreach ([$t2p1, $t2p2] as $enemy) {
                $this->incrementOpponent($ally, $enemy);
            }
        }
    }

    public function recordPartnership(int $playerA, int $playerB): void
    {
        $this->incrementPartner($playerA, $playerB);
    }

    /**
     * @param array{\App\Models\Participant, \App\Models\Participant} $team1
     * @param array{\App\Models\Participant, \App\Models\Participant} $team2
     */
    public function recordDoublesMatch(array $team1, array $team2): void
    {
        $this->recordMatchup(
            $team1[0]->id,
            $team1[1]->id,
            $team2[0]->id,
            $team2[1]->id,
        );
    }

    public function partnerCount(int $playerA, int $playerB): int
    {
        [$a, $b] = $this->normalizePair($playerA, $playerB);

        return $this->partners[$a][$b] ?? 0;
    }

    public function opponentCount(int $playerA, int $playerB): int
    {
        [$a, $b] = $this->normalizePair($playerA, $playerB);

        return $this->opponents[$a][$b] ?? 0;
    }

    private function incrementPartner(int $playerA, int $playerB): void
    {
        [$a, $b] = $this->normalizePair($playerA, $playerB);
        $this->partners[$a][$b] = ($this->partners[$a][$b] ?? 0) + 1;
    }

    private function incrementOpponent(int $playerA, int $playerB): void
    {
        [$a, $b] = $this->normalizePair($playerA, $playerB);
        $this->opponents[$a][$b] = ($this->opponents[$a][$b] ?? 0) + 1;
    }

    /** @return array{0: int, 1: int} */
    private function normalizePair(int $playerA, int $playerB): array
    {
        return $playerA < $playerB ? [$playerA, $playerB] : [$playerB, $playerA];
    }
}
