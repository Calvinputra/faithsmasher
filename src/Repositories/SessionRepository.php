<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\Session;
use App\Models\SessionSummary;
use App\Support\BaganSettings;
use App\Support\MatchPairingMode;
use App\Support\Paginator;
use PDO;

final class SessionRepository
{
    private const SELECT_FIELDS = 's.id, s.user_id, s.name, s.session_date, s.location, s.court_count, s.bagan_count, s.bagan_pairing_scope, s.bagan_pairing_mode, s.bagan_pairing_modes, s.bagan_share_token, s.created_at, s.updated_at, s.updated_by_user_id';

    private const AUDIT_JOINS = ' LEFT JOIN users uc ON uc.id = s.user_id LEFT JOIN users uu ON uu.id = s.updated_by_user_id ';

    private const AUDIT_NAMES = ', uc.name AS created_by_name, uu.name AS updated_by_name';

    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    /**
     * @return array{sessions: list<SessionSummary>, paginator: Paginator}
     */
    public function paginate(string $search, string $date, int $page, int $perPage): array
    {
        $filters = $this->buildFilters($search, $date);
        $paginator = Paginator::fromTotal($this->countAll($search, $date), $page, $perPage);

        $sql = 'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . ',
                       COUNT(sp.participant_id) AS participant_count
                FROM sessions s' . self::AUDIT_JOINS . '
                LEFT JOIN session_participants sp ON sp.session_id = s.id
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

    public function countAll(string $search = '', string $date = ''): int
    {
        $filters = $this->buildFilters($search, $date);
        $statement = $this->db->prepare('SELECT COUNT(*) FROM sessions s WHERE ' . $filters['sql']);
        $this->bindFilterParams($statement, $filters['params']);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{sessionCount: int, participantCount: int, courtCount: int}
     */
    public function stats(string $search = '', string $date = ''): array
    {
        $filters = $this->buildFilters($search, $date);
        $filter = $filters['sql'];
        $params = $filters['params'];

        $sessionSql = "SELECT COUNT(*) FROM sessions s WHERE {$filter}";
        $participantSql = 'SELECT COUNT(*) FROM participants';
        $courtSql = "SELECT COALESCE(SUM(s.court_count), 0) FROM sessions s WHERE {$filter}";

        return [
            'sessionCount' => $this->fetchInt($sessionSql, $params),
            'participantCount' => $this->fetchInt($participantSql, []),
            'courtCount' => $this->fetchInt($courtSql, $params),
        ];
    }

    /** @return list<string> Dates in Y-m-d format */
    public function distinctDates(): array
    {
        $statement = $this->db->query(
            'SELECT DISTINCT session_date FROM sessions ORDER BY session_date ASC'
        );

        if ($statement === false) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['session_date'],
            $statement->fetchAll(),
        );
    }

    /**
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildFilters(string $search, string $date): array
    {
        $search = trim($search);
        $date = trim($date);
        $sql = '1=1';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (s.name LIKE :search OR s.location LIKE :search2 OR DATE_FORMAT(s.session_date, \'%d %M %Y\') LIKE :search3 OR DATE_FORMAT(s.session_date, \'%e %M %Y\') LIKE :search4)';
            $like = '%' . $search . '%';
            $params['search'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
            $params['search4'] = $like;
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

    public function find(int $id): ?Session
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . ' FROM sessions s' . self::AUDIT_JOINS . ' WHERE s.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row ? Session::fromRow($row) : null;
    }

    public function findByShareToken(string $token): ?Session
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . ' FROM sessions s' . self::AUDIT_JOINS . ' WHERE s.bagan_share_token = :token LIMIT 1'
        );
        $statement->execute(['token' => $token]);

        $row = $statement->fetch();

        return $row ? Session::fromRow($row) : null;
    }

    public function ensureShareToken(int $id): string
    {
        $session = $this->find($id);

        if ($session === null) {
            throw new \RuntimeException('Session not found.');
        }

        if ($session->baganShareToken !== null && $session->baganShareToken !== '') {
            return $session->baganShareToken;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(16));

            try {
                $statement = $this->db->prepare(
                    'UPDATE sessions SET bagan_share_token = :token WHERE id = :id AND bagan_share_token IS NULL'
                );
                $statement->execute(['token' => $token, 'id' => $id]);

                if ($statement->rowCount() > 0) {
                    return $token;
                }

                $refreshed = $this->find($id);

                if ($refreshed?->baganShareToken !== null && $refreshed->baganShareToken !== '') {
                    return $refreshed->baganShareToken;
                }
            } catch (\PDOException) {
            }
        }

        throw new \RuntimeException('Failed to create share token.');
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

        return $this->find((int) $this->db->lastInsertId())
            ?? throw new \RuntimeException('Failed to create session.');
    }

    public function update(
        int $id,
        string $name,
        string $sessionDate,
        string $location,
        int $courtCount,
        int $updatedByUserId,
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE sessions SET name = :name, session_date = :session_date, location = :location, court_count = :court_count,
             updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );

        return $statement->execute([
            'id' => $id,
            'name' => $name,
            'session_date' => $sessionDate,
            'location' => $location,
            'court_count' => $courtCount,
            'updated_by_user_id' => $updatedByUserId,
        ]);
    }

    public function updateBaganSettings(int $id, BaganSettings $settings, int $updatedByUserId): bool
    {
        $columns = $settings->toSessionColumns();
        $statement = $this->db->prepare(
            'UPDATE sessions SET
                bagan_count = :bagan_count,
                bagan_pairing_scope = :bagan_pairing_scope,
                bagan_pairing_mode = :bagan_pairing_mode,
                bagan_pairing_modes = :bagan_pairing_modes,
                updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );

        return $statement->execute([
            'id' => $id,
            'bagan_count' => $columns['bagan_count'],
            'bagan_pairing_scope' => $columns['bagan_pairing_scope'],
            'bagan_pairing_mode' => $columns['bagan_pairing_mode'],
            'bagan_pairing_modes' => $columns['bagan_pairing_modes'],
            'updated_by_user_id' => $updatedByUserId,
        ]);
    }

    public function delete(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM sessions WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }
}
