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
    public function paginateByUser(int $userId, string $search, string $date, int $page, int $perPage): array
    {
        $filters = $this->buildUserFilters($userId, $search, $date);
        $paginator = Paginator::fromTotal($this->countByUser($userId, $search, $date), $page, $perPage);

        $sql = 'SELECT ' . self::SELECT_FIELDS . ',
                       COUNT(p.id) AS participant_count
                FROM sessions s
                LEFT JOIN participants p ON p.session_id = s.id
                WHERE ' . $filters['sql'] . '
                GROUP BY s.id
                ORDER BY s.created_at DESC, s.id DESC
                LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);
        $this->bindFilterParams($statement, $filters['params']);
        $statement->bindValue('limit', $paginator->perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $paginator->offset(), PDO::PARAM_INT);
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

    public function countByUser(int $userId, string $search = '', string $date = ''): int
    {
        $filters = $this->buildUserFilters($userId, $search, $date);
        $statement = $this->db->prepare('SELECT COUNT(*) FROM sessions s WHERE ' . $filters['sql']);
        $this->bindFilterParams($statement, $filters['params']);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{sessionCount: int, participantCount: int, courtCount: int}
     */
    public function statsByUser(int $userId, string $search = '', string $date = ''): array
    {
        $filters = $this->buildUserFilters($userId, $search, $date);
        $filter = $filters['sql'];
        $params = $filters['params'];

        $sessionSql = "SELECT COUNT(*) FROM sessions s WHERE {$filter}";
        $participantSql = "SELECT COUNT(p.id)
                           FROM participants p
                           INNER JOIN sessions s ON s.id = p.session_id
                           WHERE {$filter}";
        $courtSql = "SELECT COALESCE(SUM(s.court_count), 0) FROM sessions s WHERE {$filter}";

        return [
            'sessionCount' => $this->fetchInt($sessionSql, $params),
            'participantCount' => $this->fetchInt($participantSql, $params),
            'courtCount' => $this->fetchInt($courtSql, $params),
        ];
    }

    /** @return list<string> Dates in Y-m-d format */
    public function distinctDatesByUser(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT session_date FROM sessions WHERE user_id = :user_id ORDER BY session_date ASC'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(
            static fn (array $row): string => (string) $row['session_date'],
            $statement->fetchAll(),
        );
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildUserFilters(int $userId, string $search, string $date): array
    {
        $search = trim($search);
        $date = trim($date);
        $sql = 's.user_id = :user_id';
        $params = ['user_id' => $userId];

        if ($search !== '') {
            $sql .= ' AND (s.name LIKE :search OR s.location LIKE :search2)';
            $like = '%' . $search . '%';
            $params['search'] = $like;
            $params['search2'] = $like;
        }

        if ($date !== '') {
            $sql .= ' AND s.session_date = :session_date';
            $params['session_date'] = $date;
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /** @param array<string, mixed> $params */
    private function bindFilterParams(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
    }

    /** @param array<string, mixed> $params */
    private function fetchInt(string $sql, array $params): int
    {
        $statement = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
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
