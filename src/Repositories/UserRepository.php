<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\User;
use PDO;

final class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->db->prepare('SELECT id, name, email, created_at FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);

        $row = $statement->fetch();

        return $row ? User::fromRow($row) : null;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->db->prepare('SELECT id, name, email, created_at FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row ? User::fromRow($row) : null;
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function create(string $name, string $email, string $passwordHash): User
    {
        $statement = $this->db->prepare(
            'INSERT INTO users (name, email, password) VALUES (:name, :email, :password)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash,
        ]);

        $user = $this->findById((int) $this->db->lastInsertId());

        if ($user === null) {
            throw new \RuntimeException('Gagal membuat user.');
        }

        return $user;
    }

    public function verifyPassword(string $email, string $password): ?User
    {
        $statement = $this->db->prepare('SELECT id, name, email, password, created_at FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);

        $row = $statement->fetch();

        if (!$row || !password_verify($password, $row['password'])) {
            return null;
        }

        return User::fromRow($row);
    }
}
