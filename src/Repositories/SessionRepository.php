<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\Session;
use App\Models\SessionSummary;
use App\Support\Paginator;
use PDO;

final class SessionRepository
{
    private const SELECT_FIELDS = 's.id, s.user_id, s.name, s.session_date, s.location, s.court_count, s.created_at';

    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    /**
     * @return array{sessions: list<SessionSummary>, paginator: Paginator}
     */
    public function paginateByUser(int $userId, string $search, int $page, int $perPage): array
    {
        $search = trim($search);
        $like = '%' . $search . '%';
        $paginator = Paginator::fromTotal($this->countByUser($userId, $search), $page, $perPage);

        $sql = 'SELECT ' . self::SELECT_FIELDS . ',
                       COUNT(p.id) AS participant_count
                FROM sessions s
                LEFT JOIN participants p ON p.session_id = s.id
                WHERE s.user_id = :user_id';

        if ($search !== '') {
            $sql .= ' AND (s.name LIKE :search OR s.location LIKE :search2)';
        }

        $sql .= ' GROUP BY s.id
                  ORDER BY s.created_at DESC, s.id DESC
                  LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $paginator->perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $paginator->offset(), PDO::PARAM_INT);

        if ($search !== '') {
            $statement->bindValue('search', $like);
            $statement->bindValue('search2', $like);
        }

        $statement->execute();

        $sessions = [];

        foreach ($statement->fetchAll() as $row) {
            $sessions[] = new SessionSummary(
                Session::fromRow($row),
                (int) $row['participant_count'],
            );
        }

        return ['sessions' => $sessions, 'paginator' => $paginator];
    }

    public function countByUser(int $userId, string $search = ''): int
    {
        $search = trim($search);
        $sql = 'SELECT COUNT(*) FROM sessions s WHERE s.user_id = :user_id';

        if ($search !== '') {
            $sql .= ' AND (s.name LIKE :search OR s.location LIKE :search2)';
        }

        $statement = $this->db->prepare($sql);
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $statement->bindValue('search', $like);
            $statement->bindValue('search2', $like);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function find(int $id, int $userId): ?Session
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . ' FROM sessions s WHERE s.id = :id AND s.user_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);

        $row = $statement->fetch();

        return $row ? Session::fromRow($row) : null;
    }

    public function create(
        int $userId,
        string $name,
        string $sessionDate,
        string $location,
        int $courtCount,
    ): Session {
        $statement = $this->db->prepare(
            'INSERT INTO sessions (user_id, name, session_date, location, court_count)
             VALUES (:user_id, :name, :session_date, :location, :court_count)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'session_date' => $sessionDate,
            'location' => $location,
            'court_count' => $courtCount,
        ]);

        return $this->find((int) $this->db->lastInsertId(), $userId)
            ?? throw new \RuntimeException('Failed to create session.');
    }

    public function update(
        int $id,
        int $userId,
        string $name,
        string $sessionDate,
        string $location,
        int $courtCount,
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE sessions SET name = :name, session_date = :session_date, location = :location, court_count = :court_count
             WHERE id = :id AND user_id = :user_id'
        );

        return $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'session_date' => $sessionDate,
            'location' => $location,
            'court_count' => $courtCount,
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        $statement = $this->db->prepare('DELETE FROM sessions WHERE id = :id AND user_id = :user_id');

        return $statement->execute(['id' => $id, 'user_id' => $userId]);
    }
}
