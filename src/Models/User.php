<?php

declare(strict_types=1);

namespace App\Models;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @param array{id: int|string, name: string, email: string, created_at: string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['name'],
            $row['email'],
            $row['created_at'],
        );
    }
}
