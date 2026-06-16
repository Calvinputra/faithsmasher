<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Session;
use App\Repositories\SessionRepository;
use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Support\FlashBag;
use App\Support\FlashType;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

abstract class BaseController
{
    public function __construct(
        protected readonly AuthService $auth,
        protected readonly SessionRepository $sessions = new SessionRepository(),
    ) {
    }

    protected function assertCanDelete(): void
    {
        if (!$this->auth->canDelete()) {
            throw new HttpForbiddenException(new \Slim\Psr7\ServerRequest('POST', '/'));
        }
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
        $session = $this->sessions->find($sessionId);

        if ($session === null) {
            throw new HttpNotFoundException(new \Slim\Psr7\ServerRequest('GET', '/'));
        }

        return $session;
    }

    protected function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }

    protected function flashRedirect(
        ResponseInterface $response,
        string $path,
        string $message,
        string $type = FlashType::SUCCESS,
        ?string $title = null,
    ): ResponseInterface {
        FlashBag::set($message, $type, $title);

        return $this->redirect($response, $path);
    }

    /** @param array<string, mixed> $data */
    protected function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    protected function wantsJson(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /** @param array<string, mixed> $data */
    protected function jsonFlash(
        ResponseInterface $response,
        string $redirect,
        string $message,
        string $type = FlashType::SUCCESS,
        ?string $title = null,
        array $data = [],
    ): ResponseInterface {
        FlashBag::set($message, $type, $title);

        return $this->json($response, array_merge(['ok' => true, 'redirect' => $redirect], $data));
    }

    /** @param array<string, string> $errors */
    protected function jsonValidationErrors(
        ResponseInterface $response,
        array $errors,
        string $message = 'Periksa kembali data yang diisi.',
    ): ResponseInterface {
        return $this->json($response, [
            'ok' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    /** @deprecated Flash is injected globally via FlashMiddleware */
    protected function pullFlash(): ?array
    {
        return null;
    }
}
