<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\Participant;
use App\Support\GmsSource;
use App\Support\ParticipantFilter;
use App\Support\Paginator;
use App\Support\Rank;
use PDO;

final class ParticipantRepository
{
    private const SELECT_FIELDS = 'p.id, p.user_id, p.name, p.`rank`, p.gender, p.phone, p.gms_source, p.created_at, p.updated_at, p.updated_by_user_id';

    private const AUDIT_JOINS = ' LEFT JOIN users uc ON uc.id = p.user_id LEFT JOIN users uu ON uu.id = p.updated_by_user_id ';

    private const AUDIT_NAMES = ', uc.name AS created_by_name, uu.name AS updated_by_name';

    private const ORDER_BY_RANK = "FIELD(p.`rank`, 'C-','C','C+','B-','B','B+','A-','A','A+'), p.name ASC";

    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    /** @return list<Participant> */
    public function all(string $search = '', ?string $rank = null, ?string $gender = null, ?string $gmsSource = null): array
    {
        return $this->allFiltered([
            'search' => $search,
            'rank' => $rank,
            'gender' => $gender,
            'gmsSource' => $gmsSource,
        ]);
    }

    /**
     * @param array{search?: string, rank?: ?string, gender?: ?string, gmsSource?: ?string} $filters
     * @return list<Participant>
     */
    public function allFiltered(array $filters = []): array
    {
        $sql = 'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . ' FROM participants p' . self::AUDIT_JOINS . ' WHERE 1=1';
        $bindings = [];
        $this->applyGlobalFilters($sql, $bindings, $filters);
        $sql .= ' ORDER BY ' . self::ORDER_BY_RANK;

        $statement = $this->db->prepare($sql);

        foreach ($bindings as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->execute();

        return array_map(
            static fn (array $row): Participant => Participant::fromRow($row),
            $statement->fetchAll(),
        );
    }

    /**
     * @param array{search?: string, rank?: ?string, gender?: ?string, gmsSource?: ?string} $filters
     */
    public function countFiltered(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) FROM participants p WHERE 1=1';
        $bindings = [];
        $this->applyGlobalFilters($sql, $bindings, $filters);

        $statement = $this->db->prepare($sql);

        foreach ($bindings as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{search?: string, rank?: ?string, gender?: ?string, gmsSource?: ?string} $filters
     * @return array{participants: list<Participant>, paginator: Paginator}
     */
    public function paginateFiltered(array $filters, int $page, int $perPage): array
    {
        $total = $this->countFiltered($filters);
        $totalPages = (int) max(1, ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $totalPages);
        $paginator = Paginator::fromTotal($total, $page, $perPage);

        $sql = 'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . ' FROM participants p' . self::AUDIT_JOINS . ' WHERE 1=1';
        $bindings = [];
        $this->applyGlobalFilters($sql, $bindings, $filters);
        $sql .= ' ORDER BY ' . self::ORDER_BY_RANK . ' LIMIT :limit OFFSET :offset';

        $statement = $this->db->prepare($sql);

        foreach ($bindings as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $paginator->perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $paginator->offset(), PDO::PARAM_INT);
        $statement->execute();

        return [
            'participants' => array_map(
                static fn (array $row): Participant => Participant::fromRow($row),
                $statement->fetchAll(),
            ),
            'paginator' => $paginator,
        ];
    }

    /**
     * @param array{search?: string, rank?: ?string, gender?: ?string, gmsSource?: ?string} $filters
     * @param array<string, mixed> $bindings
     */
    private function applyGlobalFilters(string &$sql, array &$bindings, array $filters): void
    {
        $search = trim($filters['search'] ?? '');

        if ($search !== '') {
            $sql .= ' AND (p.name LIKE :search OR p.phone LIKE :search2 OR p.gms_source LIKE :search3)';
            $like = '%' . $search . '%';
            $bindings['search'] = $like;
            $bindings['search2'] = $like;
            $bindings['search3'] = $like;
        }

        $rank = $filters['rank'] ?? null;

        if ($rank !== null && $rank !== '') {
            $sql .= ' AND p.`rank` = :filter_rank';
            $bindings['filter_rank'] = $rank;
        }

        $gender = $filters['gender'] ?? null;

        if ($gender === ParticipantFilter::UNSET) {
            $sql .= ' AND (p.gender IS NULL OR p.gender = \'\')';
        } elseif ($gender !== null && $gender !== '') {
            $sql .= ' AND p.gender = :filter_gender';
            $bindings['filter_gender'] = $gender;
        }

        $gmsSource = $filters['gmsSource'] ?? null;

        if ($gmsSource === ParticipantFilter::UNSET) {
            $sql .= ' AND (p.gms_source IS NULL OR p.gms_source = \'\')';
        } elseif ($gmsSource !== null && $gmsSource !== '') {
            $sql .= ' AND p.gms_source = :filter_gms';
            $bindings['filter_gms'] = $gmsSource;
        }
    }

    /** @return list<Participant> */
    public function allBySession(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . '
             FROM participants p' . self::AUDIT_JOINS . '
             INNER JOIN session_participants sp ON sp.participant_id = p.id AND sp.session_id = :session_id
             ORDER BY ' . self::ORDER_BY_RANK
        );
        $statement->execute(['session_id' => $sessionId]);

        return array_map(
            static fn (array $row): Participant => Participant::fromRow($row),
            $statement->fetchAll(),
        );
    }

    /** @return list<Participant> */
    public function availableForSession(int $sessionId, string $search = ''): array
    {
        $search = trim($search);
        $sql = 'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . '
                FROM participants p' . self::AUDIT_JOINS . '
                WHERE NOT EXISTS (
                      SELECT 1 FROM session_participants sp
                      WHERE sp.participant_id = p.id AND sp.session_id = :session_id
                  )';

        if ($search !== '') {
            $sql .= ' AND (p.name LIKE :search OR p.phone LIKE :search2 OR p.gms_source LIKE :search3)';
        }

        $sql .= ' ORDER BY ' . self::ORDER_BY_RANK;

        $statement = $this->db->prepare($sql);
        $statement->bindValue('session_id', $sessionId, PDO::PARAM_INT);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $statement->bindValue('search', $like);
            $statement->bindValue('search2', $like);
            $statement->bindValue('search3', $like);
        }

        $statement->execute();

        return array_map(
            static fn (array $row): Participant => Participant::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function find(int $id): ?Participant
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . ' FROM participants p' . self::AUDIT_JOINS . ' WHERE p.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row ? Participant::fromRow($row) : null;
    }

    /** @return array<string, int> Normalized name (lowercase trim) => participant id */
    public function nameKeyIndex(): array
    {
        $statement = $this->db->query('SELECT id, name FROM participants');

        if ($statement === false) {
            return [];
        }

        $index = [];

        foreach ($statement->fetchAll() as $row) {
            $key = mb_strtolower(trim((string) $row['name']));

            if ($key === '' || isset($index[$key])) {
                continue;
            }

            $index[$key] = (int) $row['id'];
        }

        return $index;
    }

    /** @return list<int> */
    public function participantIdsInSession(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT participant_id FROM session_participants WHERE session_id = :session_id'
        );
        $statement->execute(['session_id' => $sessionId]);

        return array_map(
            static fn (array $row): int => (int) $row['participant_id'],
            $statement->fetchAll(),
        );
    }

    public function findInSession(int $id, int $sessionId): ?Participant
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . self::AUDIT_NAMES . '
             FROM participants p' . self::AUDIT_JOINS . '
             INNER JOIN session_participants sp ON sp.participant_id = p.id AND sp.session_id = :session_id
             WHERE p.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id, 'session_id' => $sessionId]);

        $row = $statement->fetch();

        return $row ? Participant::fromRow($row) : null;
    }

    public function create(
        int $userId,
        string $name,
        string $rank,
        ?string $gender,
        ?string $phone,
        ?string $gmsSource,
    ): Participant {
        $statement = $this->db->prepare(
            'INSERT INTO participants (user_id, name, `rank`, gender, phone, gms_source)
             VALUES (:user_id, :name, :rank, :gender, :phone, :gms_source)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'rank' => $rank,
            'gender' => $gender ?: null,
            'phone' => $phone ?: null,
            'gms_source' => $gmsSource ?: null,
        ]);

        return $this->find((int) $this->db->lastInsertId())
            ?? throw new \RuntimeException('Failed to create participant.');
    }

    /**
     * @param list<array{name: string, rank: string}> $rows
     * @return list<int> Created participant IDs
     */
    public function createMany(int $userId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $statement = $this->db->prepare(
            'INSERT INTO participants (user_id, name, `rank`, gender, phone, gms_source)
             VALUES (:user_id, :name, :rank, NULL, NULL, NULL)'
        );

        $ids = [];
        $this->db->beginTransaction();

        try {
            foreach ($rows as $row) {
                $statement->execute([
                    'user_id' => $userId,
                    'name' => $row['name'],
                    'rank' => $row['rank'],
                ]);
                $ids[] = (int) $this->db->lastInsertId();
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw $exception;
        }

        return $ids;
    }

    public function assignToSession(int $sessionId, int $participantId): bool
    {
        $statement = $this->db->prepare(
            'INSERT IGNORE INTO session_participants (session_id, participant_id) VALUES (:session_id, :participant_id)'
        );

        return $statement->execute([
            'session_id' => $sessionId,
            'participant_id' => $participantId,
        ]);
    }

    /** @param list<int> $participantIds */
    public function assignManyToSession(int $sessionId, array $participantIds): int
    {
        if ($participantIds === []) {
            return 0;
        }

        $statement = $this->db->prepare(
            'INSERT IGNORE INTO session_participants (session_id, participant_id) VALUES (:session_id, :participant_id)'
        );

        $assigned = 0;

        foreach ($participantIds as $participantId) {
            $statement->execute([
                'session_id' => $sessionId,
                'participant_id' => $participantId,
            ]);
            $assigned += $statement->rowCount();
        }

        return $assigned;
    }

    public function unassignFromSession(int $sessionId, int $participantId): bool
    {
        $statement = $this->db->prepare(
            'DELETE FROM session_participants WHERE session_id = :session_id AND participant_id = :participant_id'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'participant_id' => $participantId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function unassignAllFromSession(int $sessionId): void
    {
        $statement = $this->db->prepare('DELETE FROM session_participants WHERE session_id = :session_id');
        $statement->execute(['session_id' => $sessionId]);
    }

    public function update(
        int $id,
        string $name,
        string $rank,
        ?string $gender,
        ?string $phone,
        ?string $gmsSource,
        int $updatedByUserId,
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE participants SET name = :name, `rank` = :rank, gender = :gender, phone = :phone, gms_source = :gms_source,
             updated_by_user_id = :updated_by_user_id
             WHERE id = :id'
        );

        return $statement->execute([
            'id' => $id,
            'name' => $name,
            'rank' => $rank,
            'gender' => $gender ?: null,
            'phone' => $phone ?: null,
            'gms_source' => $gmsSource ?: null,
            'updated_by_user_id' => $updatedByUserId,
        ]);
    }

    public function delete(int $id): bool
    {
        $statement = $this->db->prepare('DELETE FROM participants WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function updateInlineField(int $id, string $field, ?string $value, int $updatedByUserId): bool
    {
        $columns = [
            'rank' => '`rank`',
            'gender' => 'gender',
            'gms_source' => 'gms_source',
        ];

        if (!isset($columns[$field])) {
            return false;
        }

        $statement = $this->db->prepare(
            'UPDATE participants SET ' . $columns[$field] . ' = :value, updated_by_user_id = :updated_by_user_id WHERE id = :id'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('value', $value);
        $statement->bindValue('updated_by_user_id', $updatedByUserId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() > 0;
    }

    public function countAvailableForSession(int $sessionId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM participants p
             WHERE NOT EXISTS (
                   SELECT 1 FROM session_participants sp
                   WHERE sp.participant_id = p.id AND sp.session_id = :session_id
               )'
        );
        $statement->execute(['session_id' => $sessionId]);

        return (int) $statement->fetchColumn();
    }

    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM participants')?->fetchColumn();
    }

    public function countBySession(int $sessionId): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM session_participants WHERE session_id = :session_id'
        );
        $statement->execute(['session_id' => $sessionId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    public function countByRank(int $sessionId): array
    {
        $counts = array_fill_keys(Rank::LEVELS, 0);

        $statement = $this->db->prepare(
            'SELECT p.`rank`, COUNT(*) AS total
             FROM participants p
             INNER JOIN session_participants sp ON sp.participant_id = p.id AND sp.session_id = :session_id
             GROUP BY p.`rank`'
        );
        $statement->execute(['session_id' => $sessionId]);

        foreach ($statement->fetchAll() as $row) {
            $counts[$row['rank']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<string, int> */
    public function countByRankGlobal(): array
    {
        $counts = array_fill_keys(Rank::LEVELS, 0);

        $statement = $this->db->query('SELECT `rank`, COUNT(*) AS total FROM participants GROUP BY `rank`');

        if ($statement === false) {
            return $counts;
        }

        foreach ($statement->fetchAll() as $row) {
            $counts[$row['rank']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<string, int> */
    public function countByGenderGlobal(): array
    {
        $counts = [
            'male' => 0,
            'female' => 0,
        ];

        $counts[ParticipantFilter::UNSET] = 0;

        $statement = $this->db->query(
            'SELECT gender, COUNT(*) AS total FROM participants GROUP BY gender'
        );

        if ($statement === false) {
            return $counts;
        }

        foreach ($statement->fetchAll() as $row) {
            $key = $row['gender'] === null || $row['gender'] === ''
                ? ParticipantFilter::UNSET
                : (string) $row['gender'];

            if (!array_key_exists($key, $counts)) {
                $counts[$key] = 0;
            }

            $counts[$key] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return array<string, int> */
    public function countByGmsSourceGlobal(): array
    {
        $counts = [];

        foreach (GmsSource::OPTIONS as $option) {
            $counts[$option] = 0;
        }

        $counts[ParticipantFilter::UNSET] = 0;

        $statement = $this->db->query(
            'SELECT gms_source, COUNT(*) AS total FROM participants GROUP BY gms_source'
        );

        if ($statement === false) {
            return $counts;
        }

        foreach ($statement->fetchAll() as $row) {
            $key = $row['gms_source'] === null || $row['gms_source'] === ''
                ? ParticipantFilter::UNSET
                : (string) $row['gms_source'];

            if (!array_key_exists($key, $counts)) {
                $counts[$key] = 0;
            }

            $counts[$key] = (int) $row['total'];
        }

        return $counts;
    }
}
