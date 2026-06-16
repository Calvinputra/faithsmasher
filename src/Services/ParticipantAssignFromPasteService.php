<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ParticipantRepository;
use App\Support\ParticipantBulkParser;

final class ParticipantAssignFromPasteService
{
    public function __construct(
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly ParticipantBulkParser $parser = new ParticipantBulkParser(),
    ) {
    }

    /**
     * Cocokkan nama dari paste bulk ke peserta global, lalu assign ke session.
     *
     * @return array{
     *     success: bool,
     *     assigned: int,
     *     notFound: list<string>,
     *     alreadyInSession: list<string>,
     *     duplicatesSkipped: int,
     *     errors: list<string>
     * }
     */
    public function assign(int $sessionId, string $rawInput): array
    {
        $parsed = $this->parser->parse($rawInput, 'C');

        if ($parsed['errors'] !== []) {
            return [
                'success' => false,
                'assigned' => 0,
                'notFound' => [],
                'alreadyInSession' => [],
                'duplicatesSkipped' => $parsed['skipped'],
                'errors' => $parsed['errors'],
            ];
        }

        if ($parsed['rows'] === []) {
            return [
                'success' => false,
                'assigned' => 0,
                'notFound' => [],
                'alreadyInSession' => [],
                'duplicatesSkipped' => $parsed['skipped'],
                'errors' => ['Tidak ada nama untuk diproses. Paste dari Excel atau ketik satu nama per baris.'],
            ];
        }

        $nameIndex = $this->participants->nameKeyIndex();
        $sessionParticipantIds = array_fill_keys(
            $this->participants->participantIdsInSession($sessionId),
            true,
        );

        $toAssign = [];
        $notFound = [];
        $alreadyInSession = [];
        $duplicatesSkipped = $parsed['skipped'];
        $seen = [];

        foreach ($parsed['rows'] as $row) {
            $key = mb_strtolower(trim($row['name']));

            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                ++$duplicatesSkipped;
                continue;
            }

            $seen[$key] = true;

            if (!isset($nameIndex[$key])) {
                $notFound[] = $row['name'];
                continue;
            }

            $participantId = $nameIndex[$key];

            if (isset($sessionParticipantIds[$participantId])) {
                $alreadyInSession[] = $row['name'];
                continue;
            }

            $toAssign[] = $participantId;
        }

        $toAssign = array_values(array_unique($toAssign));
        $assigned = $toAssign === []
            ? 0
            : $this->participants->assignManyToSession($sessionId, $toAssign);

        return [
            'success' => true,
            'assigned' => $assigned,
            'notFound' => $notFound,
            'alreadyInSession' => $alreadyInSession,
            'duplicatesSkipped' => $duplicatesSkipped,
            'errors' => [],
        ];
    }
}
