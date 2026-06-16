<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Support\FlashBag;
use App\Support\FlashType;
use App\Support\UserStatus;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Views\Twig;

final class UserAdminController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireSuperadmin();

        /** @var Twig $view */
        $view = $request->getAttribute('view');

        return $view->render($response, 'pages/admin/users/index.twig', [
            'users' => $this->users->allOrdered(),
            'pendingCount' => $this->users->countPending(),
        ]);
    }

    public function approve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireSuperadmin();

        $userId = (int) $args['id'];
        $current = $this->auth->user();

        if ($current !== null && $current->id === $userId) {
            return $this->flashRedirect($response, '/admin/users', 'Tidak bisa mengubah akun sendiri.', FlashType::WARNING);
        }

        $this->users->updateStatus($userId, UserStatus::APPROVED);

        return $this->flashRedirect(
            $response,
            '/admin/users',
            'User sudah disetujui dan bisa login ke dashboard.',
            FlashType::UPDATE,
            'User disetujui',
        );
    }

    public function reject(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireSuperadmin();

        $userId = (int) $args['id'];
        $current = $this->auth->user();

        if ($current !== null && $current->id === $userId) {
            return $this->flashRedirect($response, '/admin/users', 'Tidak bisa mengubah akun sendiri.', FlashType::WARNING);
        }

        $this->users->updateStatus($userId, UserStatus::REJECTED);

        return $this->flashRedirect(
            $response,
            '/admin/users',
            'Pendaftaran user ditolak.',
            FlashType::WARNING,
            'User ditolak',
        );
    }

    private function requireSuperadmin(): void
    {
        if ($this->auth->requireSuperadmin() === null) {
            throw new HttpForbiddenException(new \Slim\Psr7\ServerRequest('GET', '/admin/users'));
        }
    }

    private function flashRedirect(
        ResponseInterface $response,
        string $path,
        string $message,
        string $type = FlashType::SUCCESS,
        ?string $title = null,
    ): ResponseInterface {
        FlashBag::set($message, $type, $title);

        return $response->withHeader('Location', $path)->withStatus(302);
    }
}
