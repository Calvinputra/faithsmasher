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

        $globalOrder = 1;
        $totalMatches = 0;
        $courtCount = max(1, $session->courtCount);

        for ($bagan = 1; $bagan <= $settings->baganCount; $bagan++) {
            $mode = $settings->modeForBagan($bagan);
            $rotated = $this->rotatePlayers($players, $bagan - 1);
            $pairs = $this->pairPlayers($rotated, $mode);
            $orderInBagan = 1;
            $pairsCount = count($pairs);
            $matchesPerCourt = max(1, (int) ceil($pairsCount / $courtCount));

            foreach ($pairs as [$p1, $p2]) {
                $status = $p2 === null ? 'bye' : 'pending';
                $courtNumber = (int) floor(($orderInBagan - 1) / $matchesPerCourt) + 1;

                $this->matches->create(
                    $sessionId,
                    $bagan,
                    $globalOrder++,
                    $p1?->id,
                    $p2?->id,
                    false,
                    $status,
                    $courtNumber,
                );

                $orderInBagan++;
                $totalMatches++;
            }
        }

        return $totalMatches;
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

    /**
     * @param list<Participant> $players
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairPlayers(array $players, string $mode): array
    {
        $sorted = $players;

        usort($sorted, static function (Participant $a, Participant $b): int {
            return strcmp($a->name, $b->name);
        });

        return $mode === MatchPairingMode::GENDER
            ? $this->pairByGender($sorted)
            : $this->pairBySimilarRank($sorted);
    }

    /**
     * @param list<Participant> $players
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairBySimilarRank(array $players): array
    {
        usort($players, static function (Participant $a, Participant $b): int {
            $rankCompare = Rank::index($a->rank) <=> Rank::index($b->rank);

            return $rankCompare !== 0 ? $rankCompare : strcmp($a->name, $b->name);
        });

        /** @var array<string, list<Participant>> $groups */
        $groups = [];

        foreach ($players as $player) {
            $groups[$player->rank][] = $player;
        }

        $pairs = [];

        foreach (Rank::LEVELS as $rank) {
            if (!isset($groups[$rank])) {
                continue;
            }

            $pairs = array_merge($pairs, $this->pairSequential($groups[$rank]));
        }

        return $pairs;
    }

    /**
     * @param list<Participant> $players
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairByGender(array $players): array
    {
        usort($players, static function (Participant $a, Participant $b): int {
            $genderCompare = strcmp($a->gender ?? '', $b->gender ?? '');

            return $genderCompare !== 0 ? $genderCompare : strcmp($a->name, $b->name);
        });

        /** @var array<string, list<Participant>> $groups */
        $groups = [];

        foreach ($players as $player) {
            $key = $player->gender === null || $player->gender === ''
                ? ParticipantFilter::UNSET
                : $player->gender;
            $groups[$key][] = $player;
        }

        $order = ['male', 'female', 'other', ParticipantFilter::UNSET];
        $pairs = [];

        foreach ($order as $key) {
            if (!isset($groups[$key])) {
                continue;
            }

            $pairs = array_merge($pairs, $this->pairSequential($groups[$key]));
        }

        foreach ($groups as $key => $group) {
            if (in_array($key, $order, true)) {
                continue;
            }

            $pairs = array_merge($pairs, $this->pairSequential($group));
        }

        return $pairs;
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
