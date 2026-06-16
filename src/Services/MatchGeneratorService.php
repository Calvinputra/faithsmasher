<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Participant;
use App\Repositories\MatchRepository;
use App\Repositories\ParticipantRepository;
use App\Support\Rank;

final class MatchGeneratorService
{
    public function __construct(
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly MatchRepository $matches = new MatchRepository(),
    ) {
    }

    public function autoGenerate(int $sessionId): int
    {
        $players = $this->participants->allBySession($sessionId);

        if (count($players) < 2) {
            throw new \InvalidArgumentException('Minimal 2 participants untuk generate match.');
        }

        usort($players, static function (Participant $a, Participant $b): int {
            $rankCompare = Rank::index($a->rank) <=> Rank::index($b->rank);

            return $rankCompare !== 0 ? $rankCompare : strcmp($a->name, $b->name);
        });

        $this->matches->deleteBySession($sessionId);

        $pairs = $this->pairBySimilarRank($players);
        $order = 1;

        foreach ($pairs as [$p1, $p2]) {
            $status = $p2 === null ? 'bye' : 'pending';
            $this->matches->create(
                $sessionId,
                1,
                $order++,
                $p1?->id,
                $p2?->id,
                false,
                $status,
            );
        }

        return count($pairs);
    }

    /**
     * @param list<Participant> $players
     * @return list{array{0: Participant, 1: ?Participant}}
     */
    private function pairBySimilarRank(array $players): array
    {
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

            $group = $groups[$rank];

            while (count($group) >= 2) {
                $pairs[] = [array_shift($group), array_shift($group)];
            }

            if (count($group) === 1) {
                $pairs[] = [$group[0], null];
            }
        }

        return $pairs;
    }
}
