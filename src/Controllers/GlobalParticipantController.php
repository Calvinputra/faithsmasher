<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use App\Services\ParticipantBulkImportService;
use App\Support\FlashType;
use App\Support\Gender;
use App\Support\GmsSource;
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
            'ranks' => Rank::options(),
            'gmsSources' => GmsSource::options(),
            'search' => $search,
            'totalCount' => $this->participants->countByUser($userId),
        ]);
    }

    public function inlineUpdate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId();
        $participantId = (int) $args['id'];
        $participant = $this->participants->find($participantId, $userId);

        if ($participant === null) {
            return $this->json($response, ['ok' => false, 'error' => 'Peserta tidak ditemukan.'], 404);
        }

        $data = (array) $request->getParsedBody();
        $field = (string) ($data['field'] ?? '');
        $rawValue = array_key_exists('value', $data) ? trim((string) $data['value']) : null;

        $normalized = $this->normalizeInlineValue($field, $rawValue);
        if ($normalized === false) {
            return $this->json($response, ['ok' => false, 'error' => 'Nilai tidak valid.'], 422);
        }

        $currentValue = match ($field) {
            'rank' => $participant->rank,
            'gender' => $participant->gender,
            'gms_source' => $participant->gmsSource,
            default => null,
        };

        if ($this->inlineValuesEqual($currentValue, $normalized)) {
            return $this->json($response, [
                'ok' => true,
                'field' => $field,
                'value' => $normalized ?? '',
                'label' => $this->inlineLabel($field, $normalized),
                'pillClass' => $this->inlinePillClass($field, $normalized),
            ]);
        }

        if (!$this->participants->updateInlineField($participantId, $userId, $field, $normalized)) {
            return $this->json($response, ['ok' => false, 'error' => 'Gagal menyimpan perubahan.'], 500);
        }

        return $this->json($response, [
            'ok' => true,
            'field' => $field,
            'value' => $normalized ?? '',
            'label' => $this->inlineLabel($field, $normalized),
            'pillClass' => $this->inlinePillClass($field, $normalized),
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
            'gmsSources' => GmsSource::options(),
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
                'gmsSources' => GmsSource::options(),
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
            GmsSource::normalize($this->nullableString($data['gms_source'] ?? null)),
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
            return $this->flashRedirect(
                $response,
                '/participants',
                'Peserta tidak ditemukan.',
                FlashType::WARNING,
            );
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/global/form.twig', [
            'participant' => $participant,
            'ranks' => Rank::options(),
            'genders' => Gender::labels(),
            'gmsSources' => GmsSource::options(),
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
            return $this->flashRedirect(
                $response,
                '/participants',
                'Peserta tidak ditemukan.',
                FlashType::WARNING,
            );
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
                'gmsSources' => GmsSource::options(),
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
            GmsSource::normalize($this->nullableString($data['gms_source'] ?? null)),
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

        if (!$this->participants->delete((int) $args['id'], $this->userId())) {
            return $this->flashRedirect(
                $response,
                '/participants',
                'Peserta tidak ditemukan atau sudah dihapus.',
                FlashType::WARNING,
            );
        }

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

        $gmsSource = GmsSource::normalize($this->nullableString($data['gms_source'] ?? null));
        if ($this->nullableString($data['gms_source'] ?? null) !== null && $gmsSource === null) {
            $errors['gms_source'] = 'GMS From tidak valid.';
        }

        return $errors;
    }

    /** @return string|null|false false = invalid */
    private function normalizeInlineValue(string $field, ?string $value): string|null|false
    {
        if ($field === 'rank') {
            $rank = Rank::normalize((string) $value);

            return $rank ?? false;
        }

        if ($field === 'gender') {
            if ($value === null || $value === '') {
                return null;
            }

            return in_array($value, ['male', 'female'], true) ? $value : false;
        }

        if ($field === 'gms_source') {
            if ($value === null || $value === '') {
                return null;
            }

            return GmsSource::normalize($value) ?? false;
        }

        return false;
    }

    private function inlineLabel(string $field, ?string $value): string
    {
        return match ($field) {
            'rank' => $value ?? '—',
            'gender' => Gender::label($value),
            'gms_source' => $value ?? '—',
            default => $value ?? '—',
        };
    }

    private function inlinePillClass(string $field, ?string $value): string
    {
        return match ($field) {
            'rank' => Rank::pillClass($value ?? ''),
            'gender' => Gender::pillClass($value),
            'gms_source' => GmsSource::pillClass($value),
            default => 'inline-pill-empty',
        };
    }

    private function inlineValuesEqual(?string $current, ?string $next): bool
    {
        return ($current ?? '') === ($next ?? '');
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
