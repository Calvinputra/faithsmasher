<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\GameRule;
use PDO;

final class GameRuleRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    /** @return list<GameRule> */
    public function allBySession(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, session_id, name, win_points, lose_points, created_at
             FROM game_rules WHERE session_id = :session_id ORDER BY id ASC'
        );
        $statement->execute(['session_id' => $sessionId]);

        return array_map(
            static fn (array $row): GameRule => GameRule::fromRow($row),
            $statement->fetchAll()
        );
    }

    public function find(int $id, int $sessionId): ?GameRule
    {
        $statement = $this->db->prepare(
            'SELECT id, session_id, name, win_points, lose_points, created_at
             FROM game_rules WHERE id = :id AND session_id = :session_id LIMIT 1'
        );
        $statement->execute(['id' => $id, 'session_id' => $sessionId]);

        $row = $statement->fetch();

        return $row ? GameRule::fromRow($row) : null;
    }

    public function create(int $sessionId, string $name, int $winPoints, int $losePoints): GameRule
    {
        $statement = $this->db->prepare(
            'INSERT INTO game_rules (session_id, name, win_points, lose_points) VALUES (:session_id, :name, :win_points, :lose_points)'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'name' => $name,
            'win_points' => $winPoints,
            'lose_points' => $losePoints,
        ]);

        return $this->find((int) $this->db->lastInsertId(), $sessionId)
            ?? throw new \RuntimeException('Failed to create game rule.');
    }

    public function update(int $id, int $sessionId, string $name, int $winPoints, int $losePoints): bool
    {
        $statement = $this->db->prepare(
            'UPDATE game_rules SET name = :name, win_points = :win_points, lose_points = :lose_points
             WHERE id = :id AND session_id = :session_id'
        );

        return $statement->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'name' => $name,
            'win_points' => $winPoints,
            'lose_points' => $losePoints,
        ]);
    }

    public function delete(int $id, int $sessionId): bool
    {
        $statement = $this->db->prepare('DELETE FROM game_rules WHERE id = :id AND session_id = :session_id');
        $statement->execute(['id' => $id, 'session_id' => $sessionId]);

        return $statement->rowCount() > 0;
    }
}
