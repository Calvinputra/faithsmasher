<?php

declare(strict_types=1);

namespace App\Support;

final class ParticipantBulkTemplate
{
    public static function csvContent(): string
    {
        $lines = [
            implode(',', ['Nama', 'Rank', 'Gender', 'Telepon', 'GMS']),
            implode(',', ['Calvin', 'C', 'Male', '081234567890', 'GMS']),
            implode(',', ['Yosua', 'B+', 'Female', '081298765432', 'VIP']),
            implode(',', ['Pat', 'B', 'Male', '', 'CP']),
            implode(',', ['Kevin', 'A-', 'Male', '08111222333', 'PURI']),
            implode(',', ['Sarah', 'C+', 'Female', '081955667788', 'PLUIT']),
        ];

        return implode("\n", $lines) . "\n";
    }

    public static function filename(): string
    {
        return 'peserta-bulk-template.csv';
    }
}
