<?php

declare(strict_types=1);

namespace App\Support;

final class MatchPairingMode
{
    public const RANK = 'rank';

    public const GENDER = 'gender';
    
    public const RANK_GENDER = 'rank_gender';

    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            self::RANK => 'Per Rank (skill serupa)',
            self::GENDER => 'Per Gender',
            self::RANK_GENDER => 'Gabungan (Rank & Gender)',
        ];
    }

    public static function isValid(string $mode): bool
    {
        return $mode === self::RANK || $mode === self::GENDER || $mode === self::RANK_GENDER;
    }

    public static function normalize(string $mode): string
    {
        return self::isValid($mode) ? $mode : self::RANK;
    }
}
