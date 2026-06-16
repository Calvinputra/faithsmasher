<?php

declare(strict_types=1);

namespace App\Support;

final class SearchDateParser
{
    /** @var array<string, int> */
    private const MONTHS = [
        'januari' => 1,
        'jan' => 1,
        'februari' => 2,
        'feb' => 2,
        'maret' => 3,
        'mar' => 3,
        'april' => 4,
        'apr' => 4,
        'mei' => 5,
        'juni' => 6,
        'jun' => 6,
        'juli' => 7,
        'jul' => 7,
        'agustus' => 8,
        'agu' => 8,
        'aug' => 8,
        'september' => 9,
        'sep' => 9,
        'sept' => 9,
        'oktober' => 10,
        'okt' => 10,
        'oct' => 10,
        'november' => 11,
        'nov' => 11,
        'desember' => 12,
        'des' => 12,
        'dec' => 12,
    ];

    /**
     * @return array{text: string, date: string|null} date in Y-m-d
     */
    public static function extractFromQuery(string $query, ?int $defaultYear = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return ['text' => '', 'date' => null];
        }

        $defaultYear ??= (int) date('Y');

        if (($iso = self::parseIsoDate($query)) !== null) {
            return ['text' => '', 'date' => $iso];
        }

        if (($numeric = self::parseNumericDate($query, $defaultYear)) !== null) {
            return ['text' => '', 'date' => $numeric];
        }

        if (($natural = self::parseNaturalDate($query, $defaultYear)) !== null) {
            return $natural;
        }

        return ['text' => $query, 'date' => null];
    }

    /** @return array{text: string, date: string|null}|null */
    private static function parseNaturalDate(string $query, int $defaultYear): ?array
    {
        $pattern = '/\b(\d{1,2})\s+([a-zA-Z]+)(?:\s+(\d{4}))?\b/u';

        if (preg_match($pattern, $query, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $day = (int) $matches[1][0];
        $monthKey = strtolower($matches[2][0]);
        $year = isset($matches[3]) ? (int) $matches[3][0] : $defaultYear;

        if (!isset(self::MONTHS[$monthKey])) {
            return null;
        }

        $date = self::buildDate($year, self::MONTHS[$monthKey], $day);

        if ($date === null) {
            return null;
        }

        $before = trim(substr($query, 0, $matches[0][1]));
        $after = trim(substr($query, $matches[0][1] + strlen($matches[0][0])));
        $text = trim($before . ' ' . $after);

        return ['text' => $text, 'date' => $date];
    }

    private static function parseIsoDate(string $query): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $query) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $query));

        return self::buildDate($year, $month, $day);
    }

    private static function parseNumericDate(string $query, int $defaultYear): ?string
    {
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?$/', $query, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = isset($matches[3]) ? (int) $matches[3] : $defaultYear;

        if ($year < 100) {
            $year += 2000;
        }

        return self::buildDate($year, $month, $day);
    }

    private static function buildDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
