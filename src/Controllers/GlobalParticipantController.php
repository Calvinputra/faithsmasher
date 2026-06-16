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

final class GlobalParticipantController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly ParticipantBulkImportService $bulkImport = new ParticipantBulkImportService(),
    ) {
        parent::__construct($auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $search = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $userId = $this->userId();

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/global/index.twig', [
            'participants' => $this->participants->allByUser($userId, $search),
            'rankCounts' => $this->participants->countByRankForUser($userId),
            'genders' => Gender::labels(),
            'search' => $search,
            'totalCount' => $this->participants->countByUser($userId),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/global/form.twig', [
            'participant' => null,
            'ranks' => Rank::options(),
            'genders' => Gender::labels(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/global/form.twig', [
                'participant' => null,
                'ranks' => Rank::options(),
                'genders' => Gender::labels(),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->participants->create(
            $userId,
            trim((string) $data['name']),
            (string) $data['rank'],
            $this->nullableString($data['gender'] ?? null),
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['gms_source'] ?? null),
        );

        return $this->flashRedirect(
            $response,
            '/participants',
            'Peserta global berhasil ditambahkan. Bisa dipakai di semua session.',
            FlashType::CREATE,
        );
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $participant = $this->participants->find((int) $args['id'], $this->userId());

        if ($participant === null) {
            return $this->redirect($response, '/participants');
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/global/form.twig', [
            'participant' => $participant,
            'ranks' => Rank::options(),
            'genders' => Gender::labels(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $participantId = (int) $args['id'];
        $participant = $this->participants->find($participantId, $userId);

        if ($participant === null) {
            return $this->redirect($response, '/participants');
        }

        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/global/form.twig', [
                'participant' => $participant,
                'ranks' => Rank::options(),
                'genders' => Gender::labels(),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->participants->update(
            $participantId,
            $userId,
            trim((string) $data['name']),
            (string) $data['rank'],
            $this->nullableString($data['gender'] ?? null),
            $this->nullableString($data['phone'] ?? null),
            $this->nullableString($data['gms_source'] ?? null),
        );

        return $this->flashRedirect(
            $response,
            '/participants',
            'Data peserta global berhasil diperbarui.',
            FlashType::UPDATE,
        );
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCanDelete();
        $this->participants->delete((int) $args['id'], $this->userId());

        return $this->flashRedirect(
            $response,
            '/participants',
            'Peserta dihapus dari daftar global (terlepas dari session).',
            FlashType::DELETE,
        );
    }

    public function bulk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/global/bulk.twig', [
            'ranks' => Rank::options(),
            'errors' => [],
            'rowErrors' => [],
            'old' => [],
            'preview' => [],
        ]);
    }

    public function bulkStore(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $rawInput = trim((string) ($data['bulk_data'] ?? ''));
        $fileContent = $this->readUploadedText($request->getUploadedFiles()['bulk_file'] ?? null);

        if ($fileContent !== null && $fileContent !== '') {
            $rawInput = $fileContent;
        }

        $result = $this->bulkImport->importGlobal(
            $this->userId(),
            $rawInput,
            (string) ($data['default_rank'] ?? 'C'),
        );

        if (!$result['success']) {
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/participants/global/bulk.twig', [
                'ranks' => Rank::options(),
                'errors' => $result['errors'],
                'rowErrors' => $result['errors'],
                'old' => $data,
                'preview' => $result['preview'],
            ]);
        }

        $message = "{$result['imported']} peserta global berhasil diimport.";

        if ($result['skipped'] > 0) {
            $message .= " ({$result['skipped']} baris header/kosong dilewati)";
        }

        return $this->flashRedirect(
            $response,
            '/participants',
            $message,
            FlashType::CREATE,
            'Import peserta global',
        );
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    private function validate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Nama wajib diisi.';
        }

        $rank = (string) ($data['rank'] ?? '');
        if (!Rank::isValid($rank)) {
            $errors['rank'] = 'Rank wajib dipilih.';
        }

        $gender = $this->nullableString($data['gender'] ?? null);
        if (!Gender::isValid($gender)) {
            $errors['gender'] = 'Gender tidak valid.';
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
