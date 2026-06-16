<?php

declare(strict_types=1);

namespace App\Models;

final class Session
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $sessionDate,
        public readonly string $location,
        public readonly int $courtCount,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            $row['name'],
            $row['session_date'],
            $row['location'] ?? '',
            (int) ($row['court_count'] ?? 1),
            $row['created_at'],
        );
    }
}
