<?php

declare(strict_types=1);

namespace App\Models;

final class TournamentMatch
{
    public function __construct(
        public readonly int $id,
        public readonly int $sessionId,
        public readonly int $roundNumber,
        public readonly int $matchOrder,
        public readonly ?int $courtNumber,
        public readonly ?int $participant1Id,
        public readonly ?int $participant2Id,
        public readonly string $status,
        public readonly ?int $score1,
        public readonly ?int $score2,
        public readonly ?int $winnerId,
        public readonly bool $isManual,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['session_id'],
            (int) $row['round_number'],
            (int) $row['match_order'],
            isset($row['court_number']) && $row['court_number'] !== null ? (int) $row['court_number'] : null,
            isset($row['participant1_id']) ? (int) $row['participant1_id'] : null,
            isset($row['participant2_id']) ? (int) $row['participant2_id'] : null,
            $row['status'],
            isset($row['score1']) ? (int) $row['score1'] : null,
            isset($row['score2']) ? (int) $row['score2'] : null,
            isset($row['winner_id']) ? (int) $row['winner_id'] : null,
            (bool) $row['is_manual'],
        );
    }
}
