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

        $targetMatchCount = $this->computeTargetMatchCount(count($players));
        $totalPairs = 0;
        $courtCount = max(1, $session->courtCount);
        $lastPlayedMatch = $this->buildLastPlayedMap($existingRows, $targetMatchCount);

        $bagansToGenerate = $targetBagan !== null ? [$targetBagan] : range(1, $settings->baganCount);

        foreach ($bagansToGenerate as $bagan) {
            $mode = $settings->modeForBagan($bagan);
            $rotated = $this->rotatePlayers($players, $bagan - 1);
            $matchups = $this->buildBaganMatchups($rotated, $history, $mode, $targetMatchCount, $bagan - 1);

            if ($matchups === []) {
                continue;
            }

            $scheduleStart = $this->sessionGlobalOrder($bagan, 1, $targetMatchCount);
            $scheduledMatchups = $this->scheduleMatchups($matchups, $lastPlayedMatch, $scheduleStart);
            $totalPairs += $this->persistScheduledMatchups(
                $sessionId,
                $bagan,
                $scheduledMatchups,
                $targetMatchCount,
                $courtCount,
                $lastPlayedMatch,
            );
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
        $targetMatchCount = $this->computeTargetMatchCount(count($players));
        $remainingMatchCount = $targetMatchCount - count($fixedMatchups);

        if ($remainingMatchCount < 0) {
            throw new \InvalidArgumentException(
                "Request melebihi kapasitas bagan (max {$targetMatchCount} match untuk " . count($players) . ' peserta).',
            );
        }

        $remainingIds = array_map(static fn (Participant $player): int => $player->id, $remaining);
        $allPlayerIds = array_map(static fn (Participant $player): int => $player->id, $players);
        $generated = $remainingMatchCount > 0
            ? $this->buildBaganMatchups(
                $players,
                $history,
                $mode,
                $remainingMatchCount,
                $baganNum - 1,
                $remainingIds,
                $allPlayerIds,
            )
            : [];
        $allMatchups = $this->filterValidMatchups(array_merge($fixedMatchups, $generated));

        if (count($allMatchups) < $targetMatchCount) {
            throw new \InvalidArgumentException(
                'Tidak cukup match valid untuk bagan ini (' . count($allMatchups) . " dari {$targetMatchCount}). "
                . 'Kurangi jumlah request atau generate ulang semua bagan.',
            );
        }

        if ($allMatchups === []) {
            throw new \InvalidArgumentException('Tidak ada match yang bisa dibuat untuk bagan ini.');
        }

        $this->matches->deleteBySession($sessionId, $baganNum);

        $courtCount = max(1, $session->courtCount);
        $lastPlayedMatch = $this->buildLastPlayedMap($existingRows, $targetMatchCount);
        $scheduleStart = $this->sessionGlobalOrder($baganNum, 1, $targetMatchCount);
        $scheduledMatchups = $this->scheduleMatchups($allMatchups, $lastPlayedMatch, $scheduleStart);

        return $this->persistScheduledMatchups(
            $sessionId,
            $baganNum,
            $scheduledMatchups,
            $targetMatchCount,
            $courtCount,
            $lastPlayedMatch,
        );
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

    private function computeTargetMatchCount(int $playerCount): int
    {
        if ($playerCount < 1) {
            return 0;
        }

        return max(1, (int) ceil($playerCount / 4));
    }

    private function sessionGlobalOrder(int $baganNum, int $localOrder, int $matchesPerBagan): int
    {
        return ($baganNum - 1) * $matchesPerBagan + $localOrder;
    }

    /**
     * @param list<array{match: \App\Models\TournamentMatch, p1: ?\App\Models\Participant, p2: ?\App\Models\Participant}> $rows
     * @return array<int, int>
     */
    private function buildLastPlayedMap(array $rows, int $matchesPerBagan): array
    {
        $lastPlayedMatch = [];

        foreach ($rows as $row) {
            $match = $row['match'];
            $effectiveOrder = $this->sessionGlobalOrder($match->roundNumber, $match->matchOrder, $matchesPerBagan);

            if ($match->participant1Id) {
                $lastPlayedMatch[$match->participant1Id] = max(
                    $lastPlayedMatch[$match->participant1Id] ?? 0,
                    $effectiveOrder,
                );
            }

            if ($match->participant2Id) {
                $lastPlayedMatch[$match->participant2Id] = max(
                    $lastPlayedMatch[$match->participant2Id] ?? 0,
                    $effectiveOrder,
                );
            }
        }

        return $lastPlayedMatch;
    }

    /**
     * Pool slot pemain per bagan: semua main min 1×, sisanya diisi double jika n mod 4 ≠ 0.
     *
     * @param list<int> $playerIds
     * @param list<int>|null $duplicateSourceIds
     * @return list<int>
     */
    private function buildPlayerIdPool(
        array $playerIds,
        int $targetMatchCount,
        int $baganShift,
        ?array $duplicateSourceIds = null,
    ): array {
        $playerIds = array_values($playerIds);

        if ($playerIds === []) {
            return [];
        }

        $duplicateSourceIds = array_values($duplicateSourceIds ?? $playerIds);
        $totalSlots = $targetMatchCount * 4;
        $pool = $playerIds;
        $extraNeeded = $totalSlots - count($pool);

        for ($i = 0; $i < $extraNeeded; $i++) {
            $pick = $this->pickDuplicatePlayerId($pool, $duplicateSourceIds, $i + $baganShift);
            $pool[] = $pick;
        }

        return $pool;
    }

    /**
     * @param list<int> $pool
     * @param list<int> $duplicateSourceIds
     */
    private function pickDuplicatePlayerId(array $pool, array $duplicateSourceIds, int $shift): int
    {
        $counts = array_count_values($pool);
        $bestId = $duplicateSourceIds[$shift % count($duplicateSourceIds)];
        $bestCount = $counts[$bestId] ?? 0;

        foreach ($duplicateSourceIds as $offset => $candidateId) {
            $index = ($shift + $offset) % count($duplicateSourceIds);
            $id = $duplicateSourceIds[$index];
            $count = $counts[$id] ?? 0;

            if ($count < $bestCount) {
                $bestCount = $count;
                $bestId = $id;
            }
        }

        return $bestId;
    }

    /**
     * @param list<Participant> $players
     * @param list<int>|null $playerIdsOverride
     * @param list<int>|null $duplicateSourceIds
     * @return list{array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}}}
     */
    private function buildBaganMatchups(
        array $players,
        PairingHistory $history,
        string $mode,
        ?int $targetMatchCount = null,
        int $baganShift = 0,
        ?array $playerIdsOverride = null,
        ?array $duplicateSourceIds = null,
    ): array {
        $playerCount = count($players);

        if ($playerCount < 2) {
            return $this->buildLegacyRemainderOnly($players, $history, $mode);
        }

        $playerById = [];

        foreach ($players as $player) {
            $playerById[$player->id] = $player;
        }

        $targetMatchCount ??= $this->computeTargetMatchCount($playerCount);
        $baseIds = $playerIdsOverride ?? array_map(static fn (Participant $player): int => $player->id, $players);
        $idPool = $this->buildPlayerIdPool($baseIds, $targetMatchCount, $baganShift, $duplicateSourceIds);
        $matchups = [];

        while (count($matchups) < $targetMatchCount && count($idPool) >= 4) {
            $poolParticipants = array_map(static fn (int $id): Participant => $playerById[$id], $idPool);
            $match = $this->findBestDoublesMatch($poolParticipants, $history, $mode, strict: true);

            if ($match === null) {
                shuffle($idPool);
                $poolParticipants = array_map(static fn (int $id): Participant => $playerById[$id], $idPool);
                $match = $this->findBestDoublesMatch($poolParticipants, $history, $mode, strict: true);
            }

            if ($match === null) {
                $poolParticipants = array_map(static fn (int $id): Participant => $playerById[$id], $idPool);
                $match = $this->findBestDoublesMatch($poolParticipants, $history, $mode, strict: false);
            }

            if ($match === null) {
                $match = $this->forceDoublesMatchFromPool($idPool, $playerById);
            }

            if ($match === null) {
                break;
            }

            [$team1, $team2] = $match['teams'];
            $history->recordDoublesMatch($team1, $team2);
            $matchups[] = [$team1, $team2];
            $idPool = $this->consumePlayersFromIdPool($idPool, $match['players']);
        }

        if (count($matchups) < $targetMatchCount) {
            $legacy = $this->buildLegacyRemainderOnly(
                array_map(static fn (int $id): Participant => $playerById[$id], $idPool),
                $history,
                $mode,
            );

            foreach ($legacy as $extra) {
                if (count($matchups) >= $targetMatchCount) {
                    break;
                }

                $matchups[] = $extra;
            }
        }

        if (count($matchups) < $targetMatchCount && count($idPool) >= 4) {
            throw new \InvalidArgumentException(
                'Generator hanya menghasilkan ' . count($matchups) . " dari {$targetMatchCount} match. "
                . 'Coba generate ulang bagan atau sesuaikan request match.',
            );
        }

        return $matchups;
    }

    /**
     * @param list<mixed> $matchups
     * @return list{array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}}}
     */
    private function filterValidMatchups(array $matchups): array
    {
        return array_values(array_filter(
            $matchups,
            static function (mixed $matchup): bool {
                if (!is_array($matchup) || !isset($matchup[0]) || !is_array($matchup[0])) {
                    return false;
                }

                return isset($matchup[0][0], $matchup[0][1]);
            },
        ));
    }

    /**
     * @param list{array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}>} $scheduledMatchups
     */
    private function persistScheduledMatchups(
        int $sessionId,
        int $baganNum,
        array $scheduledMatchups,
        int $targetMatchCount,
        int $courtCount,
        array &$lastPlayedMatch,
    ): int {
        $validMatchups = $this->filterValidMatchups($scheduledMatchups);
        $totalPairs = 0;

        foreach ($validMatchups as $index => $matchup) {
            $team1 = $matchup[0];
            $team2 = is_array($matchup[1] ?? null) ? $matchup[1] : null;
            $localOrder = $index + 1;
            $courtNumber = ($index % $courtCount) + 1;

            $this->matches->create(
                $sessionId,
                $baganNum,
                $localOrder,
                $team1[0]->id,
                $team1[1]->id,
                false,
                'pending',
                $courtNumber,
            );
            $totalPairs++;

            if ($team2 !== null && isset($team2[0], $team2[1])) {
                $this->matches->create(
                    $sessionId,
                    $baganNum,
                    $localOrder,
                    $team2[0]->id,
                    $team2[1]->id,
                    false,
                    'pending',
                    $courtNumber,
                );
                $totalPairs++;
            }

            $this->updateLastPlayed(
                $matchup,
                $lastPlayedMatch,
                $this->sessionGlobalOrder($baganNum, $localOrder, $targetMatchCount),
            );
        }

        return $totalPairs;
    }

    /**
     * Fallback lama untuk sisa pemain (BYE / match tidak penuh) — tetap dipakai jika pool tidak bisa dipenuhi.
     *
     * @param list<Participant> $players
     * @return list{array{0: array{0: Participant, 1: Participant}, 1: ?array{0: Participant, 1: Participant}}}
     */
    private function buildLegacyRemainderOnly(array $players, PairingHistory $history, string $mode): array
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
            $remaining = $this->removePlayerInstances($remaining, $match['players']);
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
     * @param list<int> $idPool
     * @param array<int, Participant> $playerById
     * @return ?array{teams: array{0: array{0: Participant, 1: Participant}, 1: array{0: Participant, 1: Participant}}, players: list<Participant>, score: float}
     */
    private function forceDoublesMatchFromPool(array $idPool, array $playerById): ?array
    {
        if (count($idPool) < 4) {
            return null;
        }

        $uniqueIds = array_values(array_unique($idPool));

        if (count($uniqueIds) >= 4) {
            $participants = array_map(static fn (int $id): Participant => $playerById[$id], $uniqueIds);
            $quads = $this->combinations($participants, 4);

            if (count($quads) > 120) {
                shuffle($quads);
                $quads = array_slice($quads, 0, 120);
            }

            foreach ($quads as $four) {
                $forced = $this->forceDoublesMatchFromParticipants($four);

                if ($forced !== null) {
                    return $forced;
                }
            }
        }

        for ($offset = 0, $limit = count($idPool) - 3; $offset < $limit; $offset++) {
            $slice = array_slice($idPool, $offset, 4);
            $participants = array_map(static fn (int $id): Participant => $playerById[$id], $slice);
            $forced = $this->forceDoublesMatchFromParticipants($participants);

            if ($forced !== null) {
                return $forced;
            }
        }

        return null;
    }

    /**
     * @param list<Participant> $participants
     * @return ?array{teams: array{0: array{0: Participant, 1: Participant}, 1: array{0: Participant, 1: Participant}}, players: list<Participant>, score: float}
     */
    private function forceDoublesMatchFromParticipants(array $participants): ?array
    {
        if (count($participants) < 4) {
            return null;
        }

        foreach ($this->splitQuadsIntoTeams($participants) as [$team1, $team2]) {
            if ($team1[0]->id === $team1[1]->id || $team2[0]->id === $team2[1]->id) {
                continue;
            }

            return [
                'teams' => [$team1, $team2],
                'players' => $participants,
                'score' => 0.0,
            ];
        }

        return null;
    }

    /**
     * @param list<int> $idPool
     * @param list<Participant> $players
     * @return list<int>
     */
    private function consumePlayersFromIdPool(array $idPool, array $players): array
    {
        foreach ($players as $player) {
            $index = array_search($player->id, $idPool, true);

            if ($index !== false) {
                unset($idPool[$index]);
            }
        }

        return array_values($idPool);
    }

    /**
     * @param list<Participant> $remaining
     * @param list<Participant> $toRemove
     * @return list<Participant>
     */
    private function removePlayerInstances(array $remaining, array $toRemove): array
    {
        $pool = $remaining;

        foreach ($toRemove as $player) {
            foreach ($pool as $index => $candidate) {
                if ($candidate->id === $player->id) {
                    unset($pool[$index]);
                    break;
                }
            }
        }

        return array_values($pool);
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
                if ($team1[0]->id === $team1[1]->id || $team2[0]->id === $team2[1]->id) {
                    continue;
                }

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
        $matchups = $this->filterValidMatchups($matchups);
        $scheduled = [];
        $unassigned = $matchups;

        for ($i = 0, $total = count($matchups); $i < $total; $i++) {
            if ($unassigned === []) {
                break;
            }

            $bestScore = -1;
            $bestIndex = null;

            foreach ($unassigned as $index => $matchup) {
                $score = $this->calculateMatchupRestScore($matchup, $lastPlayedMatch, $startOrder + $i);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex === null || !isset($unassigned[$bestIndex]) || !is_array($unassigned[$bestIndex])) {
                break;
            }

            $scheduled[] = $unassigned[$bestIndex];
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
