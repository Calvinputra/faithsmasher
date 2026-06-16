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
     * @return array{
     *     success: bool,
     *     imported: int,
     *     skipped: int,
     *     errors: list<string>,
     *     preview: list<array{name: string, rank: string}>,
     *     matchesGenerated: int|null
     * }
     */
    public function import(
        int $sessionId,
        string $rawInput,
        string $defaultRank = 'C',
        bool $replaceExisting = false,
        bool $generateMatches = false,
    ): array {
        $defaultRank = Rank::normalize($defaultRank) ?? 'C';
        $parsed = $this->parser->parse($rawInput, $defaultRank);

        if ($parsed['errors'] !== []) {
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => $parsed['skipped'],
                'errors' => $parsed['errors'],
                'preview' => $parsed['rows'],
                'matchesGenerated' => null,
            ];
        }

        if ($parsed['rows'] === []) {
            return [
                'success' => false,
                'imported' => 0,
                'skipped' => $parsed['skipped'],
                'errors' => ['Tidak ada peserta untuk diimport.'],
                'preview' => [],
                'matchesGenerated' => null,
            ];
        }

        if ($replaceExisting) {
            $this->matches->deleteBySession($sessionId);
            $this->participants->deleteAllBySession($sessionId);
        }

        $imported = $this->participants->createMany($sessionId, $parsed['rows']);
        $matchesGenerated = null;

        if ($generateMatches && $imported >= 2) {
            try {
                $matchesGenerated = $this->matchGenerator->autoGenerate($sessionId);
            } catch (\InvalidArgumentException) {
                $matchesGenerated = 0;
            }
        }

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $parsed['skipped'],
            'errors' => [],
            'preview' => $parsed['rows'],
            'matchesGenerated' => $matchesGenerated,
        ];
    }
}
