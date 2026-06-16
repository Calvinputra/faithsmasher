<?php

declare(strict_types=1);

namespace App\Support;

final class ParticipantBulkParser
{
    /** @var array<string, list<string>> */
    private const COLUMN_ALIASES = [
        'no' => ['no', '#', 'nomor', 'num', 'number'],
        'name' => ['nama', 'name', 'peserta', 'player'],
        'rank' => ['rank', 'level', 'tingkat'],
        'gender' => ['gender', 'jenis kelamin', 'jk', 'kelamin'],
        'phone' => ['telepon', 'telp', 'phone', 'no telp', 'notelpon', 'no. telp', 'whatsapp', 'wa', 'hp', 'no telpon'],
        'gms_source' => ['gms', 'gms from', 'gms_source', 'gms dari', 'gms dari mana', 'cabang', 'from'],
    ];

    /**
     * @return array{
     *     rows: list<array{
     *         name: string,
     *         rank: string,
     *         gender: ?string,
     *         phone: ?string,
     *         gms_source: ?string
     *     }>,
     *     errors: list<string>,
     *     skipped: int
     * }
     */
    public function parse(string $input, string $defaultRank = 'C'): array
    {
        $input = trim(str_replace("\r\n", "\n", $input));

        if ($input === '') {
            return ['rows' => [], 'errors' => ['Data kosong. Paste dari Excel atau upload file CSV.'], 'skipped' => 0];
        }

        $defaultRank = Rank::normalize($defaultRank) ?? 'C';
        $delimiter = $this->detectDelimiter($input);
        $lines = explode("\n", $input);
        $rows = [];
        $errors = [];
        $skipped = 0;
        $columnMap = null;

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $cells = array_map(trim(...), str_getcsv($line, $delimiter, '"', '\\'));

            if ($this->isEmptyRow($cells)) {
                continue;
            }

            if ($columnMap === null) {
                $headerMap = $this->parseHeaderMap($cells);

                if ($headerMap !== null) {
                    $columnMap = $headerMap;
                    ++$skipped;
                    continue;
                }
            }

            if ($columnMap !== null) {
                $parsed = $this->parseMappedRow($cells, $columnMap, $defaultRank, $lineNumber);

                if ($parsed === null) {
                    ++$skipped;
                    continue;
                }

                if (isset($parsed['error'])) {
                    $errors[] = "Baris {$lineNumber}: {$parsed['error']}";
                    continue;
                }

                $rows[] = $parsed;
                continue;
            }

            if ($this->isLegacyHeaderRow($cells)) {
                ++$skipped;
                continue;
            }

            $parsed = $this->parsePositionalRow($cells, $defaultRank, $lineNumber);

            if ($parsed === null) {
                ++$skipped;
                continue;
            }

            if (isset($parsed['error'])) {
                $errors[] = "Baris {$lineNumber}: {$parsed['error']}";
                continue;
            }

            $rows[] = $parsed;
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Tidak ada peserta valid. Gunakan format: Nama,Rank,Gender,Telepon,GMS atau unduh template CSV.';
        }

        return ['rows' => $rows, 'errors' => $errors, 'skipped' => $skipped];
    }

    private function detectDelimiter(string $input): string
    {
        if (str_contains($input, "\t")) {
            return "\t";
        }

        if (str_contains($input, ';')) {
            return ';';
        }

        return ',';
    }

    /** @param list<string> $cells */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $cells
     * @return array<string, int>|null
     */
    private function parseHeaderMap(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            $field = $this->matchColumnKey($cell);

            if ($field !== null && !isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        if (!isset($map['name'])) {
            return null;
        }

        return $map;
    }

    private function matchColumnKey(string $header): ?string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $header) ?? $header));

        foreach (self::COLUMN_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalized === $alias || str_contains($normalized, $alias)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $cells
     * @param array<string, int> $map
     * @return array{
     *     name: string,
     *     rank: string,
     *     gender: ?string,
     *     phone: ?string,
     *     gms_source: ?string
     * }|array{error: string}|null
     */
    private function parseMappedRow(array $cells, array $map, string $defaultRank, int $lineNumber): ?array
    {
        $name = trim($cells[$map['name']] ?? '');

        if ($name === '') {
            return null;
        }

        $rankInput = isset($map['rank']) ? trim($cells[$map['rank']] ?? '') : '';
        $rank = $rankInput !== '' ? Rank::normalize($rankInput) : $defaultRank;

        if ($rank === null) {
            return ['error' => "Rank tidak valid \"{$rankInput}\". Gunakan: " . implode(', ', Rank::LEVELS) . '.'];
        }

        $gender = null;
        if (isset($map['gender'])) {
            $genderInput = trim($cells[$map['gender']] ?? '');
            if ($genderInput !== '') {
                $gender = Gender::normalize($genderInput);
                if ($gender === null) {
                    return ['error' => $this->invalidGenderMessage($genderInput)];
                }
            }
        }

        $phone = null;
        if (isset($map['phone'])) {
            $phoneInput = trim($cells[$map['phone']] ?? '');
            if ($phoneInput !== '') {
                $phone = $this->normalizePhone($phoneInput);
                if ($phone === null) {
                    return ['error' => 'Nomor telepon tidak valid (max 20 digit).'];
                }
            }
        }

        $gmsSource = null;
        if (isset($map['gms_source'])) {
            $gmsInput = trim($cells[$map['gms_source']] ?? '');
            if ($gmsInput !== '') {
                $gmsSource = GmsSource::normalize($gmsInput);
                if ($gmsSource === null) {
                    return ['error' => $this->invalidGmsMessage($gmsInput)];
                }
            }
        }

        return $this->buildRow($name, $rank, $gender, $phone, $gmsSource);
    }

    /** @param list<string> $cells */
    private function isLegacyHeaderRow(array $cells): bool
    {
        if ($this->parseHeaderMap($cells) !== null) {
            return true;
        }

        $joined = strtolower(implode(' ', $cells));

        if (preg_match('/^week\s*\d+/i', $joined) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $cells
     * @return array{
     *     name: string,
     *     rank: string,
     *     gender: ?string,
     *     phone: ?string,
     *     gms_source: ?string
     * }|array{error: string}|null
     */
    private function parsePositionalRow(array $cells, string $defaultRank, int $lineNumber): ?array
    {
        if ($this->isNumber($cells[0] ?? '') && count($cells) > 1) {
            $cells = array_values(array_slice($cells, 1));
        }

        $count = count($cells);

        if ($count === 1) {
            return $this->buildRow($cells[0], $defaultRank, null, null, null);
        }

        if ($count === 2) {
            [$first, $second] = $cells;

            if ($this->isNumber($first) && Rank::normalize($second) === null) {
                return $this->buildRow($second, $defaultRank, null, null, null);
            }

            $rank = Rank::normalize($second);

            if ($rank !== null) {
                return $this->buildRow($first, $rank, null, null, null);
            }

            return ['error' => $this->invalidRankMessage($second)];
        }

        $name = $cells[0];
        $rankInput = $cells[1];
        $rank = Rank::normalize($rankInput);

        if ($rank === null) {
            return ['error' => $this->invalidRankMessage($rankInput)];
        }

        $extras = array_slice($cells, 2);
        $gender = null;
        $phone = null;
        $gmsSource = null;

        foreach ($extras as $extra) {
            $extra = trim($extra);

            if ($extra === '') {
                continue;
            }

            if ($gender === null && ($parsedGender = Gender::normalize($extra)) !== null) {
                $gender = $parsedGender;
                continue;
            }

            if ($gmsSource === null && ($parsedGms = GmsSource::normalize($extra)) !== null) {
                $gmsSource = $parsedGms;
                continue;
            }

            if ($phone === null && $this->looksLikePhone($extra)) {
                $phone = $this->normalizePhone($extra);
                if ($phone === null) {
                    return ['error' => 'Nomor telepon tidak valid (max 20 digit).'];
                }
                continue;
            }

            return ['error' => "Kolom \"{$extra}\" tidak dikenali. Gunakan Gender (Male/Female), Telepon, atau GMS (" . implode(', ', GmsSource::OPTIONS) . ').'];
        }

        return $this->buildRow($name, $rank, $gender, $phone, $gmsSource);
    }

    /**
     * @return array{
     *     name: string,
     *     rank: string,
     *     gender: ?string,
     *     phone: ?string,
     *     gms_source: ?string
     * }|array{error: string}|null
     */
    private function buildRow(
        string $name,
        string $rank,
        ?string $gender,
        ?string $phone,
        ?string $gmsSource,
    ): ?array {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > 100) {
            return ['error' => 'Nama terlalu panjang (max 100 karakter).'];
        }

        return [
            'name' => $name,
            'rank' => $rank,
            'gender' => $gender,
            'phone' => $phone,
            'gms_source' => $gmsSource,
        ];
    }

    private function invalidRankMessage(string $value): string
    {
        return "Rank tidak valid \"{$value}\". Gunakan: " . implode(', ', Rank::LEVELS) . '.';
    }

    private function invalidGenderMessage(string $value): string
    {
        return "Gender tidak valid \"{$value}\". Gunakan: Male, Female, atau Other.";
    }

    private function invalidGmsMessage(string $value): string
    {
        return "GMS tidak valid \"{$value}\". Gunakan: " . implode(', ', GmsSource::OPTIONS) . '.';
    }

    private function looksLikePhone(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits !== '' && strlen($digits) >= 8;
    }

    private function normalizePhone(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $value) ?? '';

        if ($normalized === '' || strlen($normalized) > 20) {
            return null;
        }

        return $normalized;
    }

    private function isNumber(string $value): bool
    {
        return preg_match('/^\d+$/', trim($value)) === 1;
    }
}
