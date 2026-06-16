<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Session;
use App\Repositories\SessionRepository;
use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Response;

abstract class BaseController
{
    public function __construct(
        protected readonly AuthService $auth,
        protected readonly SessionRepository $sessions = new SessionRepository(),
    ) {
    }

    protected function userId(): int
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new HttpNotFoundException(new \Slim\Psr7\ServerRequest('GET', '/'));
        }

        return $user->id;
    }

    protected function requireSession(int $sessionId): Session
    {
        $session = $this->sessions->find($sessionId, $this->userId());

        if ($session === null) {
            throw new HttpNotFoundException(new \Slim\Psr7\ServerRequest('GET', '/'));
        }

        return $session;
    }

    protected function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }

    protected function flashRedirect(ResponseInterface $response, string $path, string $message, string $type = 'success'): ResponseInterface
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];

        return $this->redirect($response, $path);
    }

    /** @return array{message: string, type: string}|null */
    protected function pullFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : null;
    }
}
