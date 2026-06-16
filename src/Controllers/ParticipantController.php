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

        return $view->render($response, 'pages/participants/index.twig', [
            'session' => $session,
            'participants' => $this->participants->allBySession($session->id),
            'rankCounts' => $this->participants->countByRank($session->id),
            'genders' => Gender::labels(),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/form.twig', [
            'session' => $session,
            'participant' => null,
            'ranks' => Rank::options(),
            'genders' => Gender::labels(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/form.twig', [
                'session' => $session,
                'participant' => null,
                'ranks' => Rank::options(),
                'genders' => Gender::labels(),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->participants->create(
            $sessionId,
            trim((string) $data['name']),
            (string) $data['rank'],
            $this->nullableString($data['gender'] ?? null),
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['gms_source'] ?? null),
        );

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            'Peserta baru berhasil ditambahkan.',
            FlashType::CREATE,
        );
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $participant = $this->participants->find((int) $args['id'], $sessionId);

        if ($participant === null) {
            return $this->redirect($response, '/sessions/' . $sessionId . '/participants');
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/form.twig', [
            'session' => $session,
            'participant' => $participant,
            'ranks' => Rank::options(),
            'genders' => Gender::labels(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $participantId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/form.twig', [
                'session' => $session,
                'participant' => $this->participants->find($participantId, $sessionId),
                'ranks' => Rank::options(),
                'genders' => Gender::labels(),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->participants->update(
            $participantId,
            $sessionId,
            trim((string) $data['name']),
            (string) $data['rank'],
            $this->nullableString($data['gender'] ?? null),
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['gms_source'] ?? null),
        );

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            'Data peserta berhasil diperbarui.',
            FlashType::UPDATE,
        );
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCanDelete();
        $sessionId = (int) $args['sessionId'];
        $this->participants->delete((int) $args['id'], $sessionId);

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/participants',
            'Peserta berhasil dihapus dari session.',
            FlashType::DELETE,
        );
    }

    public function bulk(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/bulk.twig', [
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

        $defaultRank = (string) ($data['default_rank'] ?? 'C');
        $replaceExisting = isset($data['replace_existing']) && $this->auth->canDelete();
        $generateMatches = isset($data['generate_matches']);

        $result = $this->bulkImport->import(
            $sessionId,
            $rawInput,
            $defaultRank,
            $replaceExisting,
            $generateMatches,
        );

        if (!$result['success']) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/bulk.twig', [
                'session' => $session,
                'ranks' => Rank::options(),
                'errors' => $result['errors'],
                'rowErrors' => $result['errors'],
                'old' => $data,
                'preview' => $result['preview'],
            ]);
        }

        $message = "{$result['imported']} peserta berhasil diimport.";

        if ($result['skipped'] > 0) {
            $message .= " ({$result['skipped']} baris header/kosong dilewati)";
        }

        if ($generateMatches) {
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

    /** @param array<string, mixed> $data @return array<string, string> */
    private function validate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Name is required.';
        }

        $rank = (string) ($data['rank'] ?? '');
        if (!Rank::isValid($rank)) {
            $errors['rank'] = 'Rank is required.';
        }

        $gender = $this->nullableString($data['gender'] ?? null);
        if (!Gender::isValid($gender)) {
            $errors['gender'] = 'Invalid gender value.';
        }

        return $errors;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
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
