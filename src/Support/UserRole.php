<?php

declare(strict_types=1);

namespace App\Support;

final class UserRole
{
    public const SUPERADMIN = 'superadmin';
    public const ADMIN = 'admin';

    public static function isValid(string $role): bool
    {
        return in_array($role, [self::SUPERADMIN, self::ADMIN], true);
    }
}
