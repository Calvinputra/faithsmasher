<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\User;
use App\Support\UserRole;
use App\Support\UserStatus;
use PDO;

final class UserRepository
{
    private const SELECT_FIELDS = 'id, name, email, role, status, created_at';

    private ?PDO $db = null;

    private function db(): PDO
    {
        if ($this->db === null) {
            $this->db = Connection::get();
        }

        return $this->db;
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->db()->prepare(
            'SELECT ' . self::SELECT_FIELDS . ' FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        $row = $statement->fetch();

        return $row ? User::fromRow($row) : null;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->db()->prepare(
            'SELECT ' . self::SELECT_FIELDS . ' FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row ? User::fromRow($row) : null;
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    public function createPending(string $name, string $email, string $passwordHash): User
    {
        $statement = $this->db()->prepare(
            'INSERT INTO users (name, email, password, role, status)
             VALUES (:name, :email, :password, :role, :status)'
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash,
            'role' => UserRole::ADMIN,
            'status' => UserStatus::PENDING,
        ]);

        $user = $this->findById((int) $this->db()->lastInsertId());

        if ($user === null) {
            throw new \RuntimeException('Gagal membuat user.');
        }

        return $user;
    }

    public function verifyPassword(string $email, string $password): ?User
    {
        $statement = $this->db()->prepare(
            'SELECT id, name, email, password, role, status, created_at FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        $row = $statement->fetch();

        if (!$row || !password_verify($password, $row['password'])) {
            return null;
        }

        return User::fromRow($row);
    }

    /** @return list<User> */
    public function allOrdered(): array
    {
        $statement = $this->db()->query(
            'SELECT ' . self::SELECT_FIELDS . ' FROM users
             ORDER BY FIELD(status, \'pending\', \'approved\', \'rejected\'), created_at DESC'
        );

        return array_map(
            static fn (array $row): User => User::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function countPending(): int
    {
        $statement = $this->db()->prepare('SELECT COUNT(*) FROM users WHERE status = :status');
        $statement->execute(['status' => UserStatus::PENDING]);

        return (int) $statement->fetchColumn();
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!UserStatus::isValid($status)) {
            return false;
        }

        $statement = $this->db()->prepare('UPDATE users SET status = :status WHERE id = :id');
        $statement->execute(['id' => $id, 'status' => $status]);

        return $statement->rowCount() > 0;
    }
}
