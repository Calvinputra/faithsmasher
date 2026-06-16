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
     * @return array{
     *     success: bool,
     *     imported: int,
     *     skipped: int,
     *     duplicatesSkipped: int,
     *     assignedExisting: int,
     *     errors: list<string>,
     *     preview: list<array{name: string, rank: string}>
     * }
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
     *     duplicatesSkipped: int,
     *     assignedExisting: int,
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
        return $this->importRows($userId, $sessionId, $rawInput, $defaultRank, $replaceExisting, $generateMatches);
    }

    /**
     * @return array{
     *     success: bool,
     *     imported: int,
     *     skipped: int,
     *     duplicatesSkipped: int,
     *     assignedExisting: int,
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

        $prepared = $this->prepareImportRows($parsed['rows'], $sessionId);

        if ($prepared['create'] === [] && $prepared['assignIds'] === []) {
            return [
                'success' => true,
                'imported' => 0,
                'skipped' => $parsed['skipped'],
                'duplicatesSkipped' => $prepared['duplicatesSkipped'],
                'assignedExisting' => 0,
                'errors' => [],
                'preview' => $parsed['rows'],
                'matchesGenerated' => null,
            ];
        }

        $newIds = $this->participants->createMany($userId, $prepared['create']);

        $assignedExisting = 0;

        if ($sessionId !== null) {
            $assignedExisting = $this->participants->assignManyToSession(
                $sessionId,
                array_merge($newIds, $prepared['assignIds']),
            );
        }

        $matchesGenerated = null;
        $sessionParticipantCount = $sessionId !== null
            ? $this->participants->countBySession($sessionId)
            : 0;

        if ($sessionId !== null && $generateMatches && $sessionParticipantCount >= 2) {
            try {
                $matchesGenerated = $this->matchGenerator->autoGenerate($sessionId);
            } catch (\InvalidArgumentException) {
                $matchesGenerated = 0;
            }
        }

        return [
            'success' => true,
            'imported' => count($newIds),
            'skipped' => $parsed['skipped'],
            'duplicatesSkipped' => $prepared['duplicatesSkipped'],
            'assignedExisting' => $assignedExisting,
            'errors' => [],
            'preview' => $prepared['create'],
            'matchesGenerated' => $matchesGenerated,
        ];
    }

    /**
     * @param list<array{name: string, rank: string}> $rows
     * @return array{
     *     create: list<array{name: string, rank: string}>,
     *     assignIds: list<int>,
     *     duplicatesSkipped: int
     * }
     */
    private function prepareImportRows(array $rows, ?int $sessionId): array
    {
        $nameIndex = $this->participants->nameKeyIndex();
        $sessionParticipantIds = $sessionId !== null
            ? array_fill_keys($this->participants->participantIdsInSession($sessionId), true)
            : [];

        $toCreate = [];
        $toAssignIds = [];
        $duplicatesSkipped = 0;
        $seenInBatch = [];

        foreach ($rows as $row) {
            $key = $this->normalizeNameKey($row['name']);

            if ($key === '') {
                continue;
            }

            if (isset($seenInBatch[$key])) {
                ++$duplicatesSkipped;
                continue;
            }

            $seenInBatch[$key] = true;

            if (isset($nameIndex[$key])) {
                $existingId = $nameIndex[$key];

                if ($sessionId === null) {
                    ++$duplicatesSkipped;
                    continue;
                }

                if (isset($sessionParticipantIds[$existingId])) {
                    ++$duplicatesSkipped;
                    continue;
                }

                $toAssignIds[] = $existingId;
                continue;
            }

            $toCreate[] = $row;
        }

        return [
            'create' => $toCreate,
            'assignIds' => array_values(array_unique($toAssignIds)),
            'duplicatesSkipped' => $duplicatesSkipped,
        ];
    }

    private function normalizeNameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @param list<string> $errors
     * @param list<array{name: string, rank: string}> $preview
     * @return array{
     *     success: bool,
     *     imported: int,
     *     skipped: int,
     *     duplicatesSkipped: int,
     *     assignedExisting: int,
     *     errors: list<string>,
     *     preview: list<array{name: string, rank: string}>,
     *     matchesGenerated: null
     * }
     */
    private function failure(array $errors, int $skipped, array $preview): array
    {
        return [
            'success' => false,
            'imported' => 0,
            'skipped' => $skipped,
            'duplicatesSkipped' => 0,
            'assignedExisting' => 0,
            'errors' => $errors,
            'preview' => $preview,
            'matchesGenerated' => null,
        ];
    }
}
