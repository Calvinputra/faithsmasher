<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\FlashType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class SessionController extends BaseController
{
    public function __construct(AuthService $auth)
    {
        parent::__construct($auth);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->redirect($response, '/dashboard?modal=session-form-modal');
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            if ($this->wantsJson($request)) {
                return $this->jsonValidationErrors($response, $errors);
            }

            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/sessions/form.twig', [
                'session' => null,
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $session = $this->sessions->create(
            $this->userId(),
            trim((string) $data['name']),
            (string) $data['session_date'],
            trim((string) $data['location']),
            (int) ($data['court_count'] ?? 1),
        );

        if ($this->wantsJson($request)) {
            return $this->jsonFlash(
                $response,
                '/dashboard/' . $session->id,
                'Session "' . $session->name . '" siap digunakan. Tambahkan peserta untuk mulai.',
                FlashType::CREATE,
            );
        }

        return $this->flashRedirect(
            $response,
            '/dashboard/' . $session->id,
            'Session "' . $session->name . '" siap digunakan. Tambahkan peserta untuk mulai.',
            FlashType::CREATE,
        );
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['id'];
        $this->requireSession($sessionId);

        return $this->redirect($response, '/dashboard/' . $sessionId . '?modal=session-form-modal&edit=' . $sessionId);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['id'];
        $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            if ($this->wantsJson($request)) {
                return $this->jsonValidationErrors($response, $errors);
            }

            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/sessions/form.twig', [
                'session' => $this->sessions->find($sessionId),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->sessions->update(
            $sessionId,
            trim((string) $data['name']),
            (string) $data['session_date'],
            trim((string) $data['location']),
            (int) ($data['court_count'] ?? 1),
            $this->userId(),
        );

        if ($this->wantsJson($request)) {
            return $this->jsonFlash(
                $response,
                '/dashboard/' . $sessionId,
                'Perubahan session berhasil disimpan.',
                FlashType::UPDATE,
            );
        }

        return $this->flashRedirect(
            $response,
            '/dashboard/' . $sessionId,
            'Perubahan session berhasil disimpan.',
            FlashType::UPDATE,
        );
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCanDelete();
        $sessionId = (int) $args['id'];
        $session = $this->requireSession($sessionId);

        if (!$this->sessions->delete($sessionId)) {
            return $this->flashRedirect(
                $response,
                '/dashboard',
                'Session tidak ditemukan atau sudah dihapus.',
                FlashType::WARNING,
            );
        }

        return $this->flashRedirect(
            $response,
            '/dashboard',
            'Session "' . $session->name . '" beserta rules, matches, dan assignment peserta telah dihapus.',
            FlashType::DELETE,
        );
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    private function validate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Session name is required.';
        }

        if (trim((string) ($data['session_date'] ?? '')) === '') {
            $errors['session_date'] = 'Session date is required.';
        }

        if (trim((string) ($data['location'] ?? '')) === '') {
            $errors['location'] = 'Location is required.';
        }

        $courtCount = (int) ($data['court_count'] ?? 0);
        if ($courtCount < 1 || $courtCount > 99) {
            $errors['court_count'] = 'Court count must be between 1 and 99.';
        }

        return $errors;
    }
}
