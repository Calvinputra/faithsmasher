<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use App\Services\ParticipantBulkImportService;
use App\Support\FlashType;
use App\Support\Gender;
use App\Support\Rank;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

final class ParticipantController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly ParticipantBulkImportService $bulkImport = new ParticipantBulkImportService(),
    ) {
        parent::__construct($auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/session/index.twig', [
            'session' => $session,
            'participants' => $this->participants->allBySession($session->id),
            'rankCounts' => $this->participants->countByRank($session->id),
            'genders' => Gender::labels(),
            'availableCount' => $this->participants->countAvailableForSession($session->id, $this->userId()),
        ]);
    }

    public function assign(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);
        $search = trim((string) ($request->getQueryParams()['q'] ?? ''));

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/session/assign.twig', [
            'session' => $session,
            'available' => $this->participants->availableForSession($session->id, $this->userId(), $search),
            'search' => $search,
        ]);
    }

    public function assignStore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $ids = $data['participant_ids'] ?? [];

        if (!is_array($ids)) {
            $ids = [];
        }

        $participantIds = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $userId = $this->userId();
        $validIds = [];

        foreach ($participantIds as $participantId) {
            if ($this->participants->find($participantId, $userId) !== null) {
                $validIds[] = $participantId;
            }
        }

        if ($validIds === []) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/participants/assign',
                'Pilih minimal satu peserta dari daftar global.',
                FlashType::WARNING,
            );
        }

        $assigned = $this->participants->assignManyToSession($sessionId, $validIds);

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            "{$assigned} peserta ditambahkan ke session ini.",
            FlashType::CREATE,
        );
    }

    public function unassign(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $participantId = (int) $args['id'];
        $this->requireSession($sessionId);

        if ($this->participants->findInSession($participantId, $sessionId) === null) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/participants',
                'Peserta tidak ada di session ini.',
                FlashType::WARNING,
            );
        }

        if (!$this->participants->unassignFromSession($sessionId, $participantId)) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/participants',
                'Gagal menghapus peserta dari session.',
                FlashType::ERROR,
            );
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            'Peserta dihapus dari session (data global tetap ada).',
            FlashType::DELETE,
        );
    }

    public function bulk(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/session/bulk.twig', [
            'session' => $session,
            'ranks' => Rank::options(),
            'errors' => [],
            'rowErrors' => [],
            'old' => [],
            'preview' => [],
        ]);
    }

    public function bulkStore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $rawInput = trim((string) ($data['bulk_data'] ?? ''));
        $fileContent = $this->readUploadedText($request->getUploadedFiles()['bulk_file'] ?? null);

        if ($fileContent !== null && $fileContent !== '') {
            $rawInput = $fileContent;
        }

        $result = $this->bulkImport->importToSession(
            $this->userId(),
            $sessionId,
            $rawInput,
            (string) ($data['default_rank'] ?? 'C'),
            isset($data['replace_existing']) && $this->auth->canDelete(),
            isset($data['generate_matches']),
        );

        if (!$result['success']) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/session/bulk.twig', [
                'session' => $session,
                'ranks' => Rank::options(),
                'errors' => $result['errors'],
                'rowErrors' => $result['errors'],
                'old' => $data,
                'preview' => $result['preview'],
            ]);
        }

        $message = "{$result['imported']} peserta diimport ke global & ditambahkan ke session.";

        if ($result['skipped'] > 0) {
            $message .= " ({$result['skipped']} baris header/kosong dilewati)";
        }

        if (isset($data['generate_matches'])) {
            if ($result['matchesGenerated'] !== null && $result['matchesGenerated'] > 0) {
                return $this->flashRedirect(
                    $response,
                    '/sessions/' . $sessionId . '/matches/preview',
                    $message . " {$result['matchesGenerated']} match digenerate otomatis.",
                    FlashType::CREATE,
                    'Import & generate match',
                );
            }

            if ($result['imported'] < 2) {
                $message .= ' Minimal 2 peserta untuk generate match.';
            }
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            $message,
            FlashType::CREATE,
            'Import peserta',
        );
    }

    private function readUploadedText(?UploadedFileInterface $file): ?string
    {
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        if ($file->getSize() > 1024 * 1024) {
            return null;
        }

        $stream = $file->getStream();
        $stream->rewind();

        return trim($stream->getContents());
    }
}
