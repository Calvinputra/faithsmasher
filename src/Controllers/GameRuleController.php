<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\GameRuleRepository;
use App\Services\AuthService;
use App\Support\FlashType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class GameRuleController extends BaseController
{
    public function __construct(
        AuthService $auth,
        private readonly GameRuleRepository $rules = new GameRuleRepository(),
    ) {
        parent::__construct($auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $this->requireSession((int) $args['sessionId']);
        $editRule = null;
        $editId = (int) ($request->getQueryParams()['edit'] ?? 0);

        if ($editId > 0) {
            $editRule = $this->rules->find($editId, $session->id);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/rules/index.twig', [
            'session' => $session,
            'rules' => $this->rules->allBySession($session->id),
            'editRule' => $editRule,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $this->requireSession($sessionId);

        return $this->redirect($response, '/sessions/' . $sessionId . '/rules?modal=rule-form-modal');
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            if ($this->wantsJson($request)) {
                return $this->jsonValidationErrors($response, $errors);
            }

            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/rules/form.twig', [
                'session' => $session,
                'rule' => null,
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->rules->create(
            $sessionId,
            trim((string) $data['name']),
            (int) $data['win_points'],
            (int) $data['lose_points'],
        );

        if ($this->wantsJson($request)) {
            return $this->jsonFlash(
                $response,
                '/sessions/' . $sessionId . '/rules',
                'Aturan permainan berhasil ditambahkan.',
                FlashType::CREATE,
            );
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/rules',
            'Aturan permainan berhasil ditambahkan.',
            FlashType::CREATE,
        );
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $this->requireSession($sessionId);
        $ruleId = (int) $args['id'];

        if ($this->rules->find($ruleId, $sessionId) === null) {
            return $this->redirect($response, '/sessions/' . $sessionId . '/rules');
        }

        return $this->redirect($response, '/sessions/' . $sessionId . '/rules?edit=' . $ruleId);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $sessionId = (int) $args['sessionId'];
        $session = $this->requireSession($sessionId);
        $ruleId = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $errors = $this->validate($data);

        if ($errors !== []) {
            if ($this->wantsJson($request)) {
                return $this->jsonValidationErrors($response, $errors);
            }

            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/rules/form.twig', [
                'session' => $session,
                'rule' => $this->rules->find($ruleId, $sessionId),
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        $this->rules->update(
            $ruleId,
            $sessionId,
            trim((string) $data['name']),
            (int) $data['win_points'],
            (int) $data['lose_points'],
        );

        if ($this->wantsJson($request)) {
            return $this->jsonFlash(
                $response,
                '/sessions/' . $sessionId . '/rules',
                'Aturan permainan berhasil diperbarui.',
                FlashType::UPDATE,
            );
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/rules',
            'Aturan permainan berhasil diperbarui.',
            FlashType::UPDATE,
        );
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->assertCanDelete();
        $sessionId = (int) $args['sessionId'];
        $this->requireSession($sessionId);
        $ruleId = (int) $args['id'];

        if (!$this->rules->delete($ruleId, $sessionId)) {
            return $this->flashRedirect(
                $response,
                '/sessions/' . $sessionId . '/rules',
                'Aturan tidak ditemukan atau sudah dihapus.',
                FlashType::WARNING,
            );
        }

        return $this->flashRedirect(
            $response,
            '/sessions/' . $sessionId . '/rules',
            'Aturan permainan berhasil dihapus.',
            FlashType::DELETE,
        );
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    private function validate(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = 'Rule name is required.';
        }

        if ((int) ($data['win_points'] ?? -1) < 0) {
            $errors['win_points'] = 'Win points must be 0 or more.';
        }

        if ((int) ($data['lose_points'] ?? -1) < 0) {
            $errors['lose_points'] = 'Lose points must be 0 or more.';
        }

        return $errors;
    }
}
