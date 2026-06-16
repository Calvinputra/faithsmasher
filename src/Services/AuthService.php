<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;

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

        return $this->users->findById((int) $userId);
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function login(string $email, string $password): bool
    {
        $user = $this->users->verifyPassword($email, $password);

        if ($user === null) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] = $user->id;

        return true;
    }

    /**
     * @return array{success: bool, errors: array<string, string>}
     */
    public function register(string $name, string $email, string $password, string $passwordConfirm): array
    {
        $errors = $this->validateRegistration($name, $email, $password, $passwordConfirm);

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $user = $this->users->create(
            trim($name),
            strtolower(trim($email)),
            password_hash($password, PASSWORD_DEFAULT),
        );

        $_SESSION[self::SESSION_KEY] = $user->id;

        return ['success' => true, 'errors' => []];
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
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
