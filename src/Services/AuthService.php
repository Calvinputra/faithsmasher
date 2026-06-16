<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Support\UserStatus;

final class AuthService
{
    private const SESSION_KEY = 'user_id';

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public function user(): ?User
    {
        $userId = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_int($userId) && !is_string($userId)) {
            return null;
        }

        $user = $this->users->findById((int) $userId);

        if ($user === null || !$user->isApproved()) {
            unset($_SESSION[self::SESSION_KEY]);

            return null;
        }

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function canDelete(): bool
    {
        return $this->user()?->canDelete() ?? false;
    }

    public function isSuperadmin(): bool
    {
        return $this->user()?->isSuperadmin() ?? false;
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->users->verifyPassword(strtolower(trim($email)), $password);

        if ($user === null) {
            return ['ok' => false, 'error' => 'Email atau password salah.'];
        }

        if ($user->isPending()) {
            return ['ok' => false, 'error' => 'Akun menunggu konfirmasi Superadmin.'];
        }

        if ($user->status === UserStatus::REJECTED) {
            return ['ok' => false, 'error' => 'Pendaftaran akun ditolak. Hubungi Superadmin.'];
        }

        if (!$user->isApproved()) {
            return ['ok' => false, 'error' => 'Akun belum aktif.'];
        }

        $_SESSION[self::SESSION_KEY] = $user->id;

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{success: bool, pending: bool, errors: array<string, string>}
     */
    public function register(string $name, string $email, string $password, string $passwordConfirm): array
    {
        $errors = $this->validateRegistration($name, $email, $password, $passwordConfirm);

        if ($errors !== []) {
            return ['success' => false, 'pending' => false, 'errors' => $errors];
        }

        $this->users->createPending(
            trim($name),
            strtolower(trim($email)),
            password_hash($password, PASSWORD_DEFAULT),
        );

        return ['success' => true, 'pending' => true, 'errors' => []];
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    public function requireSuperadmin(): ?User
    {
        $user = $this->user();

        if ($user === null || !$user->isSuperadmin()) {
            return null;
        }

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function validateRegistration(string $name, string $email, string $password, string $passwordConfirm): array
    {
        $errors = [];

        if (trim($name) === '') {
            $errors['name'] = 'Nama wajib diisi.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Format email tidak valid.';
        } elseif ($this->users->emailExists(strtolower(trim($email)))) {
            $errors['email'] = 'Email sudah terdaftar.';
        }

        if (strlen($password) < 8) {
            $errors['password'] = 'Password minimal 8 karakter.';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Konfirmasi password tidak cocok.';
        }

        return $errors;
    }
}
