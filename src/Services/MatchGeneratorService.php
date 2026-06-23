<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Participant;
use App\Repositories\MatchRepository;
use App\Repositories\ParticipantRepository;
use App\Repositories\SessionRepository;
use App\Support\BaganSettings;
use App\Support\MatchPairingMode;
use App\Support\ParticipantFilter;
use App\Support\Rank;

final class MatchGeneratorService
{
    public function __construct(
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly MatchRepository $matches = new MatchRepository(),
        private readonly SessionRepository $sessions = new SessionRepository(),
    ) {
    }

    public function autoGenerate(int $sessionId, ?BaganSettings $settings = null): int
    {
        $session = $this->sessions->find($sessionId);

        if ($session === null) {
            throw new \InvalidArgumentException('Session tidak ditemukan.');
        }

        $settings ??= BaganSettings::fromSession($session);
        $players = $this->participants->allBySession($sessionId);

        if (count($players) < 2) {
            throw new \InvalidArgumentException('Minimal 2 peserta untuk generate bagan.');
        }

        $this->matches->deleteBySession($sessionId);

        $globalMatchOrder = 1;
        $totalPairs = 0;
        $courtCount = max(1, $session->courtCount);

        for ($bagan = 1; $bagan <= $settings->baganCount; $bagan++) {
            $mode = $settings->modeForBagan($bagan);
            $rotated = $this->rotatePlayers($players, $bagan - 1);
            
            $pool = $rotated;
            
            $pairs = $this->pairPlayers($pool, $mode);
            $matchesCount = (int) ceil(count($pairs) / 2);

            $pairIndex = 0;
            foreach ($pairs as [$p1, $p2]) {
                // Group every 2 pairs into the same match order (Team 1 vs Team 2)
                $matchOrderInBagan = (int) floor($pairIndex / 2) + 1;
                $currentGlobalMatchOrder = (int) ($globalMatchOrder + $matchOrderInBagan - 1);
                
                // Distribute Matches across available courts (round-robin style)
                $courtIndex = ($matchOrderInBagan - 1) % $courtCount;
                $courtNumber = $courtIndex + 1;
                
                $hasOpponentTeam = ($pairIndex % 2 === 0 && isset($pairs[$pairIndex + 1])) || ($pairIndex % 2 === 1);
                $status = ($p2 === null || !$hasOpponentTeam) ? 'bye' : 'pending';

                $this->matches->create(
                    $sessionId,
                    $bagan,
                    $currentGlobalMatchOrder,
                    $p1?->id,
                    $p2?->id,
                    false,
                    $status,
                    $courtNumber,
                );

                $pairIndex++;
                $totalPairs++;
            }
            
            $globalMatchOrder += $matchesCount;
        }

        return $totalPairs;
    }

    /**
     * @param list<Participant> $players
     * @return list<Participant>
     */
    private function rotatePlayers(array $players, int $shift): array
    {
        if ($players === [] || $shift <= 0) {
            return $players;
        }

        $count = count($players);
        $shift = $shift % $count;

        return array_merge(array_slice($players, $shift), array_slice($players, 0, $shift));
    }

    private function pairPlayers(array $players, string $mode): array
    {
        return $mode === MatchPairingMode::GENDER
            ? $this->pairByGender($players)
            : $this->pairBySimilarRank($players);
    }

    /**
     * @param list<Participant> $players
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairBySimilarRank(array $players): array
    {
        $groups = [];
        foreach ($players as $p) {
            $groups[Rank::index($p->rank)][] = $p;
        }

        ksort($groups);

        $shuffled = [];
        foreach ($groups as $group) {
            shuffle($group);
            $shuffled = array_merge($shuffled, $group);
        }

        return $this->pairSequential($shuffled);
    }

    /**
     * @param list<Participant> $players
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairByGender(array $players): array
    {
        $groups = [];
        foreach ($players as $p) {
            $gender = strtolower($p->gender ?? 'unknown');
            $groups[$gender][] = $p;
        }

        ksort($groups);

        $shuffled = [];
        foreach ($groups as $group) {
            shuffle($group);
            $shuffled = array_merge($shuffled, $group);
        }

        return $this->pairSequential($shuffled);
    }

    /**
     * @param list<Participant> $group
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairSequential(array $group): array
    {
        $pairs = [];

        while (count($group) >= 2) {
            $pairs[] = [array_shift($group), array_shift($group)];
        }

        if (count($group) === 1) {
            $pairs[] = [$group[0], null];
        }

        return $pairs;
    }
}
