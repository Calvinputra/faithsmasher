<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\FlashBag;
use App\Support\FlashType;
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
            $redirectUrl = $_SESSION['intended_url'] ?? '/dashboard';
            unset($_SESSION['intended_url']);
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');
        $old = $_SESSION['old_login'] ?? [];
        unset($_SESSION['old_login']);

        return $view->render($response, 'pages/auth/login.twig', [
            'errors' => [],
            'old' => $old,
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $email = (string) ($data['email'] ?? '');
        $password = (string) ($data['password'] ?? '');

        $result = $this->auth->login($email, $password);

        if ($result['ok']) {
            FlashBag::set('Selamat datang kembali!', FlashType::SUCCESS, 'Login berhasil');

            $redirectUrl = $_SESSION['intended_url'] ?? '/dashboard';
            unset($_SESSION['intended_url']);

            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        FlashBag::set($result['error'] ?? 'Email atau password salah.', FlashType::ERROR, 'Login gagal');
        $_SESSION['old_login'] = ['email' => $email];

        return $response->withHeader('Location', '/login')->withStatus(302);
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
            'pending' => false,
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
            /** @var Twig $view */
            $view = $request->getAttribute('view');

            return $view->render($response, 'pages/auth/register.twig', [
                'errors' => [],
                'old' => [],
                'pending' => $result['pending'],
            ]);
        }

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/auth/register.twig', [
            'errors' => $result['errors'],
            'old' => [
                'name' => (string) ($data['name'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
            ],
            'pending' => false,
        ]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->auth->logout();

        FlashBag::set('Anda berhasil logout. Sampai jumpa!', FlashType::INFO, 'Logout berhasil');

        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
