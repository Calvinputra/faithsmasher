<?php

declare(strict_types=1);

namespace App\Support;

final class UserStatus
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    public static function isValid(string $status): bool
    {
        return in_array($status, [self::PENDING, self::APPROVED, self::REJECTED], true);
    }
}
