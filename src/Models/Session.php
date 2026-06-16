<?php

declare(strict_types=1);

namespace App\Models;

final class Session
{
    public function __construct(
        public readonly int $id,
        public readonly int $createdByUserId,
        public readonly string $name,
        public readonly string $sessionDate,
        public readonly string $location,
        public readonly int $courtCount,
        public readonly string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?int $updatedByUserId,
        public readonly ?string $createdByName = null,
        public readonly ?string $updatedByName = null,
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
            (string) $row['created_at'],
            isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            isset($row['updated_by_user_id']) && $row['updated_by_user_id'] !== null
                ? (int) $row['updated_by_user_id']
                : null,
            isset($row['created_by_name']) ? (string) $row['created_by_name'] : null,
            isset($row['updated_by_name']) ? (string) $row['updated_by_name'] : null,
        );
    }

    /** @deprecated Use $createdByUserId */
    public function userId(): int
    {
        return $this->createdByUserId;
    }

    public function wasUpdated(): bool
    {
        return $this->updatedByUserId !== null
            && $this->updatedAt !== null
            && $this->updatedAt !== $this->createdAt;
    }
}
