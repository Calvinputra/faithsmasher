<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\UserRole;
use App\Support\UserStatus;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly string $role,
        public readonly string $status,
        public readonly string $createdAt,
    ) {
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::SUPERADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    public function isApproved(): bool
    {
        return $this->status === UserStatus::APPROVED;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::PENDING;
    }

    public function canDelete(): bool
    {
        return $this->isSuperadmin();
    }

    /**
     * @param array{
     *     id: int|string,
     *     name: string,
     *     email: string,
     *     created_at: string,
     *     role?: string,
     *     status?: string
     * } $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['name'],
            $row['email'],
            (string) ($row['role'] ?? UserRole::ADMIN),
            (string) ($row['status'] ?? UserStatus::APPROVED),
            $row['created_at'],
        );
    }
}
