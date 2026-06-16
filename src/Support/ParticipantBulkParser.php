<?php

declare(strict_types=1);

namespace App\Support;

final class ParticipantBulkParser
{
    /** @var list<string> */
    private const HEADER_TOKENS = [
        'no', '#', 'nomor', 'num', 'number',
        'nama', 'name', 'peserta', 'player',
        'rank', 'level', 'tingkat',
        'week', 'minggu', 'session', 'tanggal', 'date',
    ];

    /**
     * @return array{
     *     rows: list<array{name: string, rank: string}>,
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

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $cells = array_map(trim(...), str_getcsv($line, $delimiter, '"', '\\'));
            $cells = array_values(array_filter($cells, static fn (string $cell): bool => $cell !== ''));

            if ($cells === []) {
                continue;
            }

            if ($this->isHeaderRow($cells)) {
                ++$skipped;
                continue;
            }

            $parsed = $this->parseRow($cells, $defaultRank);

            if ($parsed === null) {
                ++$skipped;
                continue;
            }

            if (isset($parsed['error'])) {
                $errors[] = "Baris {$lineNumber}: {$parsed['error']}";
                continue;
            }

            $rows[] = [
                'name' => $parsed['name'],
                'rank' => $parsed['rank'],
            ];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Tidak ada peserta valid. Pastikan format: Nama,Rank atau No\\tNama\\tRank.';
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
    private function isHeaderRow(array $cells): bool
    {
        $joined = strtolower(implode(' ', $cells));

        foreach (self::HEADER_TOKENS as $token) {
            if (str_contains($joined, $token) && count($cells) <= 3) {
                if (in_array(strtolower($cells[0]), ['no', '#', 'nomor', 'num', 'nama', 'name', 'rank'], true)) {
                    return true;
                }

                if (isset($cells[1]) && in_array(strtolower($cells[1]), ['nama', 'name', 'rank'], true)) {
                    return true;
                }
            }
        }

        if (preg_match('/^week\s*\d+/i', $joined) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $cells
     * @return array{name: string, rank: string}|array{error: string}|null
     */
    private function parseRow(array $cells, string $defaultRank): ?array
    {
        $count = count($cells);

        if ($count === 1) {
            $name = trim($cells[0]);

            if ($name === '') {
                return null;
            }

            return ['name' => $name, 'rank' => $defaultRank];
        }

        if ($count === 2) {
            [$first, $second] = $cells;

            if ($this->isNumber($first) && Rank::normalize($second) === null) {
                return $this->buildRow($second, $defaultRank);
            }

            $rank = Rank::normalize($second);

            if ($rank !== null) {
                return $this->buildRow($first, $rank);
            }

            return ['error' => "Rank tidak valid \"{$second}\". Gunakan C-, C, C+, B-, B, B+, A-, A, A+."];
        }

        if ($this->isNumber($cells[0])) {
            $name = $cells[1];
            $rankInput = $cells[2] ?? $defaultRank;
            $rank = Rank::normalize($rankInput);

            if ($rank === null) {
                return ['error' => "Rank tidak valid \"{$rankInput}\"."];
            }

            return $this->buildRow($name, $rank);
        }

        $rank = Rank::normalize($cells[1]);

        if ($rank === null) {
            return ['error' => "Rank tidak valid \"{$cells[1]}\"."];
        }

        return $this->buildRow($cells[0], $rank);
    }

    /** @return array{name: string, rank: string}|array{error: string}|null */
    private function buildRow(string $name, string $rank): ?array
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > 100) {
            return ['error' => 'Nama terlalu panjang (max 100 karakter).'];
        }

        return ['name' => $name, 'rank' => $rank];
    }

    private function isNumber(string $value): bool
    {
        return preg_match('/^\d+$/', trim($value)) === 1;
    }
}
