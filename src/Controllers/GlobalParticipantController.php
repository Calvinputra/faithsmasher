<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ParticipantRepository;
use App\Services\AuthService;
use App\Services\ParticipantBulkImportService;
use App\Support\FlashType;
use App\Support\Gender;
use App\Support\GmsSource;
use App\Support\ParticipantFilter;
use App\Support\Rank;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

final class GlobalParticipantController extends BaseController
{
    private const PER_PAGE = 25;

    public function __construct(
        AuthService $auth,
        private readonly ParticipantRepository $participants = new ParticipantRepository(),
        private readonly ParticipantBulkImportService $bulkImport = new ParticipantBulkImportService(),
    ) {
        parent::__construct($auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = ParticipantFilter::fromQueryParams($params);
        $repoFilters = $filter->repositoryFilters();
        $page = max(1, (int) ($params['page'] ?? 1));
        $result = $this->participants->paginateFiltered($repoFilters, $page, self::PER_PAGE);
        $totalCount = $this->participants->countAll();
        $editParticipant = null;
        $editId = (int) ($params['edit'] ?? 0);

        if ($editId > 0) {
            $editParticipant = $this->participants->find($editId);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/participants/global/index.twig', [
            'participants' => $result['participants'],
            'paginator' => $result['paginator'],
            'rankCounts' => $this->participants->countByRankGlobal(),
            'genderCounts' => $this->participants->countByGenderGlobal(),
            'gmsCounts' => $this->participants->countByGmsSourceGlobal(),
            'genders' => Gender::labels(),
            'ranks' => Rank::options(),
            'gmsSources' => GmsSource::options(),
            'filter' => $filter,
            'search' => $filter->search,
            'totalCount' => $totalCount,
            'editParticipant' => $editParticipant,
        ]);
    }

    public function inlineUpdate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $participantId = (int) $args['id'];
        $participant = $this->participants->find($participantId);

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

        if (!$this->participants->updateInlineField($participantId, $field, $normalized, $this->userId())) {
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
        return $this->redirect($response, '/participants?modal=participant-form-modal');
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId();
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            if ($this->wantsJson($request)) {
                return $this->jsonValidationErrors($response, $errors);
            }

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

        if ($this->wantsJson($request)) {
            return $this->jsonFlash(
                $response,
                '/participants',
                'Peserta global berhasil ditambahkan. Bisa dipakai di semua session.',
                FlashType::CREATE,
            );
        }

        return $this->flashRedirect(
            $response,
            '/participants',
            'Peserta global berhasil ditambahkan. Bisa dipakai di semua session.',
            FlashType::CREATE,
        );
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $participantId = (int) $args['id'];

        if ($this->participants->find($participantId) === null) {
            return $this->flashRedirect(
                $response,
                '/participants',
                'Peserta tidak ditemukan.',
                FlashType::WARNING,
            );
        }

        return $this->redirect($response, '/participants?edit=' . $participantId);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $participantId = (int) $args['id'];
        $participant = $this->participants->find($participantId);

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
            if ($this->wantsJson($request)) {
                return $this->jsonValidationErrors($response, $errors);
            }

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
            trim((string) $data['name']),
            (string) $data['rank'],
            $this->nullableString($data['gender'] ?? null),
            $this->nullableString($data['phone'] ?? null),
            GmsSource::normalize($this->nullableString($data['gms_source'] ?? null)),
            $this->userId(),
        );

        if ($this->wantsJson($request)) {
            return $this->jsonFlash(
                $response,
                '/participants',
                'Data peserta global berhasil diperbarui.',
                FlashType::UPDATE,
            );
        }

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

        if (!$this->participants->delete((int) $args['id'])) {
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

        $message = $this->buildBulkImportMessage($result, 'global');
        $flashType = $result['imported'] > 0 ? FlashType::CREATE : FlashType::WARNING;

        return $this->flashRedirect(
            $response,
            '/participants',
            $message,
            $flashType,
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
}
