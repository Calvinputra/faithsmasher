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

    public function autoGenerate(int $sessionId, ?BaganSettings $settings = null, ?int $targetBagan = null): int
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

        $this->matches->deleteBySession($sessionId, $targetBagan);

        $globalMatchOrder = 1;
        $totalPairs = 0;
        $courtCount = max(1, $session->courtCount);
        $lastPlayedMatch = [];

        if ($targetBagan !== null) {
            $existingMatches = $this->matches->allWithParticipants($sessionId);
            foreach ($existingMatches as $row) {
                $m = $row['match'];
                if ($m->roundNumber < $targetBagan) {
                    if ($m->participant1Id) $lastPlayedMatch[$m->participant1Id] = max($lastPlayedMatch[$m->participant1Id] ?? 0, $m->matchOrder);
                    if ($m->participant2Id) $lastPlayedMatch[$m->participant2Id] = max($lastPlayedMatch[$m->participant2Id] ?? 0, $m->matchOrder);
                    $globalMatchOrder = max($globalMatchOrder, $m->matchOrder + 1);
                }
            }
        }

        $bagansToGenerate = $targetBagan !== null ? [$targetBagan] : range(1, $settings->baganCount);

        foreach ($bagansToGenerate as $bagan) {
            $mode = $settings->modeForBagan($bagan);
            $rotated = $this->rotatePlayers($players, $bagan - 1);
            
            $pool = $rotated;
            
            $pairs = $this->pairPlayers($pool, $mode);
            
            // Ensure the number of pairs is even to avoid byes (team vs team)
            if (count($pairs) % 2 !== 0) {
                $extraPlayers = $pool;
                shuffle($extraPlayers);
                $pairs[] = [
                    $extraPlayers[0] ?? null,
                    $extraPlayers[1] ?? null,
                ];
            }

            $matchesCount = (int) ceil(count($pairs) / 2);

            // Group pairs into matchups
            $matchups = [];
            for ($i = 0; $i < count($pairs); $i += 2) {
                $matchups[] = [$pairs[$i], $pairs[$i + 1] ?? null];
            }

            // Schedule matchups to minimize back-to-back
            $scheduledMatchups = [];
            $unassignedMatchups = $matchups;
            
            for ($i = 0; $i < count($matchups); $i++) {
                $bestScore = -1;
                $bestMatchupIndex = -1;
                
                foreach ($unassignedMatchups as $index => $matchup) {
                    $score = $this->calculateMatchupRestScore($matchup, $lastPlayedMatch, $globalMatchOrder + $i);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatchupIndex = $index;
                    }
                }
                
                $pickedMatchup = $unassignedMatchups[$bestMatchupIndex];
                $scheduledMatchups[] = $pickedMatchup;
                unset($unassignedMatchups[$bestMatchupIndex]);
                
                // Update local memory
                $this->updateLastPlayed($pickedMatchup, $lastPlayedMatch, $globalMatchOrder + $i);
            }

            // Create matches in DB
            foreach ($scheduledMatchups as $index => $matchup) {
                $team1 = $matchup[0];
                $team2 = $matchup[1];
                
                $currentGlobalMatchOrder = $globalMatchOrder + $index;
                $courtIndex = $index % $courtCount;
                $courtNumber = $courtIndex + 1;
                
                $p1 = $team1[0] ?? null;
                $p2 = $team1[1] ?? null;
                $status1 = ($p2 === null || $team2 === null) ? 'bye' : 'pending';
                
                $this->matches->create(
                    $sessionId,
                    $bagan,
                    $currentGlobalMatchOrder,
                    $p1?->id,
                    $p2?->id,
                    false,
                    $status1,
                    $courtNumber,
                );
                
                $totalPairs++;

                if ($team2 !== null) {
                    $p3 = $team2[0] ?? null;
                    $p4 = $team2[1] ?? null;
                    $status2 = ($p4 === null || $team1 === null) ? 'bye' : 'pending'; // In practice team1 is never null here
                    
                    $this->matches->create(
                        $sessionId,
                        $bagan,
                        $currentGlobalMatchOrder,
                        $p3?->id,
                        $p4?->id,
                        false,
                        $status2,
                        $courtNumber,
                    );
                    
                    $totalPairs++;
                }
            }
            
            $globalMatchOrder += $matchesCount;
        }

        return $totalPairs;
    }

    private function calculateMatchupRestScore(array $matchup, array $lastPlayedMatch, int $currentOrder): int
    {
        $minGap = 9999;
        
        $team1 = $matchup[0] ?? [];
        $team2 = $matchup[1] ?? [];
        
        $players = [
            $team1[0] ?? null,
            $team1[1] ?? null,
            $team2[0] ?? null,
            $team2[1] ?? null,
        ];
        
        foreach ($players as $p) {
            if ($p !== null) {
                $last = $lastPlayedMatch[$p->id] ?? 0;
                $gap = $last === 0 ? 9999 : ($currentOrder - $last);
                if ($gap < $minGap) {
                    $minGap = $gap;
                }
            }
        }
        
        return $minGap;
    }

    private function updateLastPlayed(array $matchup, array &$lastPlayedMatch, int $currentOrder): void
    {
        $team1 = $matchup[0] ?? [];
        $team2 = $matchup[1] ?? [];
        
        $players = [
            $team1[0] ?? null,
            $team1[1] ?? null,
            $team2[0] ?? null,
            $team2[1] ?? null,
        ];
        
        foreach ($players as $p) {
            if ($p !== null) {
                $lastPlayedMatch[$p->id] = $currentOrder;
            }
        }
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
        if ($mode === MatchPairingMode::RANK_GENDER) {
            return $this->pairByRankAndGender($players);
        }
        
        return $mode === MatchPairingMode::GENDER
            ? $this->pairByGender($players)
            : $this->pairBySimilarRank($players);
    }

    /**
     * @param list<Participant> $players
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairByRankAndGender(array $players): array
    {
        $groups = [];
        foreach ($players as $p) {
            $gender = strtolower($p->gender ?? 'unknown');
            $rank = Rank::index($p->rank);
            // Group key combines gender and rank to group similar players
            $key = $gender . '_' . $rank;
            $groups[$key][] = $p;
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
