<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
    ) {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/auth/login.twig', [
            'errors' => [],
            'old' => [],
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if ($this->auth->login($email, $password)) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/auth/login.twig', [
            'errors' => ['email' => 'Email atau password salah.'],
            'old' => ['email' => $email],
        ]);
    }

    public function showRegister(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/auth/register.twig', [
            'errors' => [],
            'old' => [],
        ]);
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();

        $result = $this->auth->register(
            (string) ($data['name'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['password'] ?? ''),
            (string) ($data['password_confirm'] ?? ''),
        );

        if ($result['success']) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/auth/register.twig', [
            'errors' => $result['errors'],
            'old' => [
                'name' => (string) ($data['name'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
            ],
        ]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->auth->logout();

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
