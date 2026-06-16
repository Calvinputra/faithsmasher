<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Session;
use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use App\Services\ParticipantAssignFromPasteService;
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
        private readonly ParticipantAssignFromPasteService $assignFromPaste = new ParticipantAssignFromPasteService(),
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
            'availableCount' => $this->participants->countAvailableForSession($session->id),
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
            'available' => $this->participants->availableForSession($session->id, $search),
            'search' => $search,
            'genders' => Gender::labels(),
            'pasteErrors' => [],
            'old' => [],
        ]);
    }

    public function assignStore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $bulkPaste = trim((string) ($data['bulk_paste'] ?? ''));

        if ($bulkPaste !== '' || ($data['assign_mode'] ?? '') === 'paste') {
            return $this->storeAssignFromPaste($request, $response, $session, $bulkPaste);
        }
        $ids = $data['participant_ids'] ?? [];

        if (!is_array($ids)) {
            $ids = [];
        }

        $participantIds = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        $validIds = [];

        foreach ($participantIds as $participantId) {
            if ($this->participants->find($participantId) !== null) {
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

    private function storeAssignFromPaste(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Session $session,
        string $bulkPaste,
    ): ResponseInterface {
        $sessionId = $session->id;
        $search = trim((string) ($request->getQueryParams()['q'] ?? ''));

        if ($bulkPaste === '') {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/session/assign.twig', [
                'session' => $session,
                'available' => $this->participants->availableForSession($sessionId, $search),
                'search' => $search,
                'genders' => Gender::labels(),
                'pasteErrors' => ['Paste daftar nama terlebih dahulu.'],
                'old' => ['bulk_paste' => ''],
            ]);
        }

        $result = $this->assignFromPaste->assign($sessionId, $bulkPaste);

        if (!$result['success']) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/session/assign.twig', [
                'session' => $session,
                'available' => $this->participants->availableForSession($sessionId, $search),
                'search' => $search,
                'genders' => Gender::labels(),
                'pasteErrors' => $result['errors'],
                'old' => ['bulk_paste' => $bulkPaste],
            ]);
        }

        $parts = ["{$result['assigned']} peserta ditambahkan ke session dari paste."];

        if ($result['notFound'] !== []) {
            $sample = array_slice($result['notFound'], 0, 3);
            $suffix = count($result['notFound']) > 3 ? '…' : '';
            $parts[] = count($result['notFound']) . ' tidak ditemukan di global (' . implode(', ', $sample) . $suffix . ')';
        }

        if ($result['alreadyInSession'] !== []) {
            $parts[] = count($result['alreadyInSession']) . ' sudah ada di session';
        }

        if ($result['duplicatesSkipped'] > 0) {
            $parts[] = "{$result['duplicatesSkipped']} baris duplikat/header dilewati";
        }

        $flashType = $result['assigned'] > 0 ? FlashType::CREATE : FlashType::WARNING;

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            implode('. ', $parts) . '.',
            $flashType,
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

        $message = $this->buildBulkImportMessage($result, 'session');
        $flashType = ($result['imported'] > 0 || ($result['assignedExisting'] ?? 0) > 0)
            ? FlashType::CREATE
            : FlashType::WARNING;

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

            $sessionCount = $this->participants->countBySession($sessionId);

            if ($sessionCount < 2) {
                $message .= ' Minimal 2 peserta untuk generate match.';
            }
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            $message,
            $flashType,
            'Import peserta',
        );
    }

    /** @param array{imported: int, skipped: int, duplicatesSkipped: int, assignedExisting?: int} $result */
    private function buildBulkImportMessage(array $result, string $context): string
    {
        $parts = [];

        if ($result['imported'] > 0) {
            $parts[] = $context === 'global'
                ? "{$result['imported']} peserta global berhasil diimport"
                : "{$result['imported']} peserta baru diimport ke global & session";
        } else {
            $parts[] = 'Tidak ada peserta baru diimport';
        }

        if (($result['assignedExisting'] ?? 0) > 0) {
            $parts[] = "{$result['assignedExisting']} peserta existing ditambahkan ke session";
        }

        if ($result['duplicatesSkipped'] > 0) {
            $parts[] = "{$result['duplicatesSkipped']} nama duplikat dilewati";
        }

        if ($result['skipped'] > 0) {
            $parts[] = "{$result['skipped']} baris header/kosong dilewati";
        }

        return implode('. ', $parts) . '.';
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
