<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Participant;
use App\Repositories\MatchRepository;
use App\Repositories\ParticipantRepository;
use App\Repositories\SessionRepository;
use App\Support\BaganSettings;
use App\Support\MatchPairingMode;
use App\Support\PairingHistory;
use App\Support\Rank;

final class MatchGeneratorService
{
    private const MAX_COMBINATION_SAMPLES = 800;

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

        $existingRows = $this->matches->allWithParticipants($sessionId);

        if ($targetBagan !== null) {
            $existingRows = array_values(array_filter(
                $existingRows,
                static fn (array $row): bool => $row['match']->roundNumber !== $targetBagan,
            ));
        }

        $history = PairingHistory::fromSessionMatches($existingRows);

        $this->matches->deleteBySession($sessionId, $targetBagan);

        $globalMatchOrder = 1;
        $totalPairs = 0;
        $courtCount = max(1, $session->courtCount);
        $lastPlayedMatch = [];

        if ($targetBagan !== null) {
            foreach ($existingRows as $row) {
                $m = $row['match'];
                if ($m->participant1Id) {
                    $lastPlayedMatch[$m->participant1Id] = max($lastPlayedMatch[$m->participant1Id] ?? 0, $m->matchOrder);
                }
                if ($m->participant2Id) {
                    $lastPlayedMatch[$m->participant2Id] = max($lastPlayedMatch[$m->participant2Id] ?? 0, $m->matchOrder);
                }
                $globalMatchOrder = max($globalMatchOrder, $m->matchOrder + 1);
            }
        }

        $bagansToGenerate = $targetBagan !== null ? [$targetBagan] : range(1, $settings->baganCount);

        foreach ($bagansToGenerate as $bagan) {
            $mode = $settings->modeForBagan($bagan);
            $rotated = $this->rotatePlayers($players, $bagan - 1);
            $matchups = $this->buildBaganMatchups($rotated, $history, $mode);

            if ($matchups === []) {
                continue;
            }

            $scheduledMatchups = $this->scheduleMatchups($matchups, $lastPlayedMatch, $globalMatchOrder);

            foreach ($scheduledMatchups as $index => $matchup) {
                [$team1, $team2] = $matchup;
                $currentGlobalMatchOrder = $globalMatchOrder + $index;
                $courtNumber = ($index % $courtCount) + 1;

                $this->matches->create(
                    $sessionId,
                    $bagan,
                    $currentGlobalMatchOrder,
                    $team1[0]->id,
                    $team1[1]->id,
                    false,
                    'pending',
                    $courtNumber,
                );
                $totalPairs++;

                if ($team2 !== null) {
                    $this->matches->create(
                        $sessionId,
                        $bagan,
                        $currentGlobalMatchOrder,
                        $team2[0]->id,
                        $team2[1]->id,
                        false,
                        'pending',
                        $courtNumber,
                    );
                    $totalPairs++;
                }

                $this->updateLastPlayed($matchup, $lastPlayedMatch, $currentGlobalMatchOrder);
            }

            $globalMatchOrder += count($scheduledMatchups);
        }

        return $totalPairs;
    }

    /**
     * Terapkan request match manual (A & B vs C & D), sisanya di-randomize untuk bagan ini.
     *
     * @param list<array{p1: int|string, p2: int|string, p3: int|string, p4: int|string}> $requests
     */
    public function regenerateBaganWithRequests(int $sessionId, int $baganNum, array $requests): int
    {
        if ($baganNum < 1) {
            throw new \InvalidArgumentException('Nomor bagan tidak valid.');
        }

        $session = $this->sessions->find($sessionId);

        if ($session === null) {
            throw new \InvalidArgumentException('Session tidak ditemukan.');
        }

        $settings = BaganSettings::fromSession($session);
        $players = $this->participants->allBySession($sessionId);

        if ($players === []) {
            throw new \InvalidArgumentException('Belum ada peserta di session ini.');
        }

        $playerById = [];

        foreach ($players as $player) {
            $playerById[$player->id] = $player;
        }

        $fixedMatchups = $this->parseRequestedMatchups($requests, $playerById);

        $existingRows = array_values(array_filter(
            $this->matches->allWithParticipants($sessionId),
            static fn (array $row): bool => $row['match']->roundNumber !== $baganNum,
        ));

        $history = PairingHistory::fromSessionMatches($existingRows);

        foreach ($fixedMatchups as [$team1, $team2]) {
            if (!$history->isValidDoublesMatch($team1, $team2)) {
                throw new \InvalidArgumentException('Salah satu request melanggar aturan teman max 1× atau musuh max 2×.');
            }

            $history->recordDoublesMatch($team1, $team2);
        }

        $usedIds = [];

        foreach ($fixedMatchups as [$team1, $team2]) {
            foreach ([$team1[0], $team1[1], $team2[0], $team2[1]] as $player) {
                $usedIds[$player->id] = true;
            }
        }

        $remaining = array_values(array_filter(
            $players,
            static fn (Participant $player): bool => !isset($usedIds[$player->id]),
        ));

        $mode = $settings->modeForBagan($baganNum);
        $generated = $remaining !== [] ? $this->buildBaganMatchups($remaining, $history, $mode) : [];
        $allMatchups = array_merge($fixedMatchups, $generated);

        if ($allMatchups === []) {
            throw new \InvalidArgumentException('Tidak ada match yang bisa dibuat untuk bagan ini.');
        }

        $this->matches->deleteBySession($sessionId, $baganNum);

        $globalMatchOrder = 1;
        $lastPlayedMatch = [];

        foreach ($existingRows as $row) {
            $match = $row['match'];

            if ($match->participant1Id) {
                $lastPlayedMatch[$match->participant1Id] = max($lastPlayedMatch[$match->participant1Id] ?? 0, $match->matchOrder);
            }

            if ($match->participant2Id) {
                $lastPlayedMatch[$match->participant2Id] = max($lastPlayedMatch[$match->participant2Id] ?? 0, $match->matchOrder);
            }

            $globalMatchOrder = max($globalMatchOrder, $match->matchOrder + 1);
        }

        $courtCount = max(1, $session->courtCount);
        $scheduledMatchups = $this->scheduleMatchups($allMatchups, $lastPlayedMatch, $globalMatchOrder);
        $totalPairs = 0;

        foreach ($scheduledMatchups as $index => $matchup) {
            [$team1, $team2] = $matchup;
            $currentGlobalMatchOrder = $globalMatchOrder + $index;
            $courtNumber = ($index % $courtCount) + 1;

            $this->matches->create(
                $sessionId,
                $baganNum,
                $currentGlobalMatchOrder,
                $team1[0]->id,
                $team1[1]->id,
                false,
                'pending',
                $courtNumber,
            );
            $totalPairs++;

            if ($team2 !== null) {
                $this->matches->create(
                    $sessionId,
                    $baganNum,
                    $currentGlobalMatchOrder,
                    $team2[0]->id,
                    $team2[1]->id,
                    false,
                    'pending',
                    $courtNumber,
                );
                $totalPairs++;
            }

            $this->updateLastPlayed($matchup, $lastPlayedMatch, $currentGlobalMatchOrder);
        }

        return $totalPairs;
    }

    /**
     * @param array<int, Participant> $playerById
     * @param list<array{p1?: mixed, p2?: mixed, p3?: mixed, p4?: mixed}> $requests
     * @return list{array{0: array{0: Participant, 1: Participant}, 1: array{0: Participant, 1: Participant}}}
     */
    private function parseRequestedMatchups(array $requests, array $playerById): array
    {
        $fixed = [];
        $seen = [];

        foreach ($requests as $index => $request) {
            if (!is_array($request)) {
                continue;
            }

            $line = $index + 1;
            $ids = [];

            foreach (['p1', 'p2', 'p3', 'p4'] as $key) {
                if (!isset($request[$key]) || $request[$key] === '') {
                    throw new \InvalidArgumentException("Request match #{$line}: lengkapi ke-4 pemain (A, B vs C, D).");
                }

                $id = (int) $request[$key];

                if (!isset($playerById[$id])) {
                    throw new \InvalidArgumentException("Request match #{$line}: peserta tidak valid.");
                }

                if (isset($seen[$id])) {
                    throw new \InvalidArgumentException("Request match #{$line}: pemain tidak boleh dobel antar request.");
                }

                $seen[$id] = true;
                $ids[] = $id;
            }

            if ($ids[0] === $ids[1] || $ids[2] === $ids[3]) {
                throw new \InvalidArgumentException("Request match #{$line}: partner dalam satu tim harus berbeda.");
            }

            $team1 = [$playerById[$ids[0]], $playerById[$ids[1]]];
            $team2 = [$playerById[$ids[2]], $playerById[$ids[3]]];
            $fixed[] = [$team1, $team2];
        }

        return $fixed;
    }

    /**
     * @param list<Participant> $players
     * @return list{array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}}}
     */
    private function buildBaganMatchups(array $players, PairingHistory $history, string $mode): array
    {
        $remaining = array_values($players);
        $matchups = [];

        while (count($remaining) >= 4) {
            $match = $this->findBestDoublesMatch($remaining, $history, $mode, strict: true);

            if ($match === null) {
                shuffle($remaining);
                $match = $this->findBestDoublesMatch($remaining, $history, $mode, strict: true);
            }

            if ($match === null) {
                break;
            }

            [$team1, $team2] = $match['teams'];
            $history->recordDoublesMatch($team1, $team2);
            $matchups[] = [$team1, $team2];
            $remaining = $this->removePlayers($remaining, $match['players']);
        }

        if (count($remaining) >= 2) {
            $extraMatch = $this->buildRemainderMatch($remaining, $history, $mode);

            if ($extraMatch !== null) {
                $matchups[] = $extraMatch;
            }
        }

        return $matchups;
    }

    /**
     * @param list<Participant> $remaining
     * @return list<Participant>
     */
    private function removePlayers(array $remaining, array $toRemove): array
    {
        $removeIds = array_map(static fn (Participant $p): int => $p->id, $toRemove);

        return array_values(array_filter(
            $remaining,
            static fn (Participant $p): bool => !in_array($p->id, $removeIds, true),
        ));
    }

    /**
     * @param list<Participant> $remaining
     * @return ?array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}}
     */
    private function buildRemainderMatch(array $remaining, PairingHistory $history, string $mode): ?array
    {
        $count = count($remaining);

        if ($count === 2) {
            $team = $this->findBestPartnerPair($remaining, $history, $mode);

            if ($team === null) {
                $team = [$remaining[0], $remaining[1]];
            } else {
                $history->recordPartnership($team[0]->id, $team[1]->id);
            }

            return [$team, null];
        }

        if ($count === 3) {
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $team1 = [$remaining[$i], $remaining[$j]];
                    $solo = array_values(array_filter(
                        $remaining,
                        static fn (Participant $p): bool => $p->id !== $team1[0]->id && $p->id !== $team1[1]->id,
                    ))[0] ?? null;

                    if ($solo === null) {
                        continue;
                    }

                    foreach ([$team1[0], $team1[1], $solo] as $partnerCandidate) {
                        $team2 = [$solo, $partnerCandidate];

                        if ($team2[0]->id === $team2[1]->id) {
                            continue;
                        }

                        if ($history->isValidDoublesMatch($team1, $team2)) {
                            $history->recordDoublesMatch($team1, $team2);

                            return [$team1, $team2];
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<Participant> $pool
     * @return ?array{teams: array{0: array{0: Participant, 1: Participant}, 1: array{0: Participant, 1: Participant}}, players: list<Participant>, score: float}
     */
    private function findBestDoublesMatch(array $pool, PairingHistory $history, string $mode, bool $strict): ?array
    {
        $best = null;
        $quads = $this->sampleQuads($pool, 4);

        foreach ($quads as $four) {
            foreach ($this->splitQuadsIntoTeams($four) as [$team1, $team2]) {
                if ($strict && !$history->isValidDoublesMatch($team1, $team2)) {
                    continue;
                }

                $score = $this->scoreDoublesMatch($team1, $team2, $mode);

                if (!$strict) {
                    $score -= $this->constraintPenalty($team1, $team2, $history);
                }

                if ($best === null || $score > $best['score']) {
                    $best = [
                        'teams' => [$team1, $team2],
                        'players' => $four,
                        'score' => $score,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * @param list<Participant> $pool
     * @return ?array{0: Participant, 1: Participant}
     */
    private function findBestPartnerPair(array $pool, PairingHistory $history, string $mode): ?array
    {
        $best = null;

        for ($i = 0; $i < count($pool); $i++) {
            for ($j = $i + 1; $j < count($pool); $j++) {
                $team = [$pool[$i], $pool[$j]];

                if (!$history->canPartner($team[0]->id, $team[1]->id)) {
                    continue;
                }

                $score = $this->scorePartnerPair($team, $mode) + (mt_rand(0, 100) / 100);

                if ($best === null || $score > $best['score']) {
                    $best = ['team' => $team, 'score' => $score];
                }
            }
        }

        return $best['team'] ?? null;
    }

    /**
     * @param list<Participant> $players
     * @return list<list<Participant>>
     */
    private function sampleQuads(array $players, int $size): array
    {
        $count = count($players);

        if ($count < $size) {
            return [];
        }

        $all = $this->combinations($players, $size);

        if (count($all) <= self::MAX_COMBINATION_SAMPLES) {
            shuffle($all);

            return $all;
        }

        $sampled = [];
        $seen = [];

        while (count($sampled) < self::MAX_COMBINATION_SAMPLES) {
            $keys = array_rand($players, $size);
            if (!is_array($keys)) {
                $keys = [$keys];
            }

            $quad = [];
            foreach ($keys as $key) {
                $quad[] = $players[$key];
            }

            usort($quad, static fn (Participant $a, Participant $b): int => $a->id <=> $b->id);
            $hash = implode('-', array_map(static fn (Participant $p): int => $p->id, $quad));

            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $sampled[] = $quad;
            }
        }

        return $sampled;
    }

    /**
     * @param list<Participant> $items
     * @return list<list<Participant>>
     */
    private function combinations(array $items, int $choose): array
    {
        if ($choose === 0) {
            return [[]];
        }

        if (count($items) < $choose) {
            return [];
        }

        $head = $items[0];
        $tail = array_slice($items, 1);
        $with = [];
        foreach ($this->combinations($tail, $choose - 1) as $combo) {
            $with[] = array_merge([$head], $combo);
        }

        return array_merge($with, $this->combinations($tail, $choose));
    }

    /**
     * @param list<Participant> $four
     * @return list{array{0: array{0: Participant, 1: Participant}, 1: array{0: Participant, 1: Participant}}}
     */
    private function splitQuadsIntoTeams(array $four): array
    {
        return [
            [[$four[0], $four[1]], [$four[2], $four[3]]],
            [[$four[0], $four[2]], [$four[1], $four[3]]],
            [[$four[0], $four[3]], [$four[1], $four[2]]],
        ];
    }

    /**
     * @param array{0: Participant, 1: Participant} $team1
     * @param array{0: Participant, 1: Participant} $team2
     */
    private function scoreDoublesMatch(array $team1, array $team2, string $mode): float
    {
        $partnerScore = $this->scorePartnerPair($team1, $mode) + $this->scorePartnerPair($team2, $mode);
        $teamBalance = $this->scoreTeamBalance($team1, $team2);
        $random = mt_rand(0, 100) / 100;

        return $partnerScore + $teamBalance + $random;
    }

    /**
     * @param array{0: Participant, 1: Participant} $team
     */
    private function scorePartnerPair(array $team, string $mode): float
    {
        $rankDiff = abs(Rank::index($team[0]->rank) - Rank::index($team[1]->rank));
        $score = 100 - ($rankDiff * 12);

        if ($mode === MatchPairingMode::GENDER || $mode === MatchPairingMode::RANK_GENDER) {
            $g1 = strtolower($team[0]->gender ?? '');
            $g2 = strtolower($team[1]->gender ?? '');

            if ($mode === MatchPairingMode::GENDER && $g1 !== '' && $g1 === $g2) {
                $score += 8;
            }

            if ($mode === MatchPairingMode::RANK_GENDER && $g1 !== '' && $g1 === $g2) {
                $score += 5;
            }
        }

        return $score;
    }

    /**
     * @param array{0: Participant, 1: Participant} $team1
     * @param array{0: Participant, 1: Participant} $team2
     */
    private function scoreTeamBalance(array $team1, array $team2): float
    {
        $avg1 = (Rank::index($team1[0]->rank) + Rank::index($team1[1]->rank)) / 2;
        $avg2 = (Rank::index($team2[0]->rank) + Rank::index($team2[1]->rank)) / 2;

        return 120 - (abs($avg1 - $avg2) * 18);
    }

    /**
     * @param array{0: Participant, 1: Participant} $team1
     * @param array{0: Participant, 1: Participant} $team2
     */
    private function constraintPenalty(array $team1, array $team2, PairingHistory $history): float
    {
        $penalty = 0.0;

        if (!$history->canPartner($team1[0]->id, $team1[1]->id)) {
            $penalty += 500;
        }

        if (!$history->canPartner($team2[0]->id, $team2[1]->id)) {
            $penalty += 500;
        }

        foreach ($team1 as $ally) {
            foreach ($team2 as $enemy) {
                if (!$history->canOppose($ally->id, $enemy->id)) {
                    $penalty += 250;
                }
            }
        }

        return $penalty;
    }

    /**
     * @param list{array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}}} $matchups
     * @return list{array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}}}
     */
    private function scheduleMatchups(array $matchups, array $lastPlayedMatch, int $startOrder): array
    {
        $scheduled = [];
        $unassigned = $matchups;

        for ($i = 0; $i < count($matchups); $i++) {
            $bestScore = -1;
            $bestIndex = -1;

            foreach ($unassigned as $index => $matchup) {
                $score = $this->calculateMatchupRestScore($matchup, $lastPlayedMatch, $startOrder + $i);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }

            $picked = $unassigned[$bestIndex];
            $scheduled[] = $picked;
            unset($unassigned[$bestIndex]);
        }

        return $scheduled;
    }

    private function calculateMatchupRestScore(array $matchup, array $lastPlayedMatch, int $currentOrder): int
    {
        $minGap = 9999;
        $team1 = $matchup[0] ?? [];
        $team2 = $matchup[1] ?? [];

        foreach ([$team1[0] ?? null, $team1[1] ?? null, $team2[0] ?? null, $team2[1] ?? null] as $p) {
            if ($p !== null) {
                $last = $lastPlayedMatch[$p->id] ?? 0;
                $gap = $last === 0 ? 9999 : ($currentOrder - $last);
                $minGap = min($minGap, $gap);
            }
        }

        return $minGap;
    }

    private function updateLastPlayed(array $matchup, array &$lastPlayedMatch, int $currentOrder): void
    {
        $team1 = $matchup[0] ?? [];
        $team2 = $matchup[1] ?? [];

        foreach ([$team1[0] ?? null, $team1[1] ?? null, $team2[0] ?? null, $team2[1] ?? null] as $p) {
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
        $shift %= $count;

        return array_merge(array_slice($players, $shift), array_slice($players, 0, $shift));
    }
}
