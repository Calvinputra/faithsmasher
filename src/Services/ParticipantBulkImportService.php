<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MatchRepository;
use App\Repositories\ParticipantRepository;
use App\Support\ParticipantBulkParser;
use App\Support\Rank;

final class ParticipantBulkImportService
{
    public function __construct(
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly MatchRepository $matches = new MatchRepository(),
        private readonly ParticipantBulkParser $parser = new ParticipantBulkParser(),
        private readonly MatchGeneratorService $matchGenerator = new MatchGeneratorService(),
    ) {
    }

    /**
     * Import ke daftar peserta global (user).
     *
     * @return array{success: bool, imported: int, skipped: int, errors: list<string>, preview: list<array{name: string, rank: string}>}
     */
    public function importGlobal(int $userId, string $rawInput, string $defaultRank = 'C'): array
    {
        return $this->importRows($userId, null, $rawInput, $defaultRank, false, false);
    }

    /**
     * Import ke global + assign ke session.
     *
     * @return array{
     *     success: bool,
     *     imported: int,
     *     skipped: int,
     *     errors: list<string>,
     *     preview: list<array{name: string, rank: string}>,
     *     matchesGenerated: int|null
     * }
     */
    public function importToSession(
        int $userId,
        int $sessionId,
        string $rawInput,
        string $defaultRank = 'C',
        bool $replaceExisting = false,
        bool $generateMatches = false,
    ): array {
        $result = $this->importRows($userId, $sessionId, $rawInput, $defaultRank, $replaceExisting, $generateMatches);

        return $result;
    }

    /**
     * @return array{
     *     success: bool,
     *     imported: int,
     *     skipped: int,
     *     errors: list<string>,
     *     preview: list<array{name: string, rank: string}>,
     *     matchesGenerated: int|null
     * }
     */
    private function importRows(
        int $userId,
        ?int $sessionId,
        string $rawInput,
        string $defaultRank,
        bool $replaceExisting,
        bool $generateMatches,
    ): array {
        $defaultRank = Rank::normalize($defaultRank) ?? 'C';
        $parsed = $this->parser->parse($rawInput, $defaultRank);

        if ($parsed['errors'] !== []) {
            return $this->failure($parsed['errors'], $parsed['skipped'], $parsed['rows']);
        }

        if ($parsed['rows'] === []) {
            return $this->failure(['Tidak ada peserta untuk diimport.'], $parsed['skipped'], []);
        }

        if ($sessionId !== null && $replaceExisting) {
            $this->matches->deleteBySession($sessionId);
            $this->participants->unassignAllFromSession($sessionId);
        }

        $ids = $this->participants->createMany($userId, $parsed['rows']);

        if ($sessionId !== null) {
            $this->participants->assignManyToSession($sessionId, $ids);
        }

        $matchesGenerated = null;

        if ($sessionId !== null && $generateMatches && count($ids) >= 2) {
            try {
                $matchesGenerated = $this->matchGenerator->autoGenerate($sessionId);
            } catch (\InvalidArgumentException) {
                $matchesGenerated = 0;
            }
        }

        return [
            'success' => true,
            'imported' => count($ids),
            'skipped' => $parsed['skipped'],
            'errors' => [],
            'preview' => $parsed['rows'],
            'matchesGenerated' => $matchesGenerated,
        ];
    }

    /**
     * @param list<string> $errors
     * @param list<array{name: string, rank: string}> $preview
     * @return array{success: bool, imported: int, skipped: int, errors: list<string>, preview: list<array{name: string, rank: string}>, matchesGenerated: null}
     */
    private function failure(array $errors, int $skipped, array $preview): array
    {
        return [
            'success' => false,
            'imported' => 0,
            'skipped' => $skipped,
            'errors' => $errors,
            'preview' => $preview,
            'matchesGenerated' => null,
        ];
    }
}
