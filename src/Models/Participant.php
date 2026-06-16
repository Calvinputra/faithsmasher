<?php

declare(strict_types=1);

namespace App\Models;

final class Participant
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $name,
        public readonly string $rank,
        public readonly ?string $gender,
        public readonly ?string $phone,
        public readonly ?string $gmsSource,
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
            $row['rank'],
            $row['gender'] ?? null,
            $row['phone'] ?? null,
            $row['gms_source'] ?? null,
            $row['created_at'],
        );
    }
}
