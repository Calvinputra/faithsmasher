<?php

declare(strict_types=1);

namespace App\Models;

final class GameRule
{
    public function __construct(
        public readonly int $id,
        public readonly int $sessionId,
        public readonly string $name,
        public readonly int $winPoints,
        public readonly int $losePoints,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['session_id'],
            $row['name'],
            (int) $row['win_points'],
            (int) $row['lose_points'],
            $row['created_at'],
        );
    }
}
