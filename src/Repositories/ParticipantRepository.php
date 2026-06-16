<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\Participant;
use App\Support\Rank;
use PDO;

final class ParticipantRepository
{
    private const SELECT_FIELDS = 'p.id, p.user_id, p.name, p.`rank`, p.gender, p.phone, p.gms_source, p.created_at';

    private const ORDER_BY_RANK = "FIELD(p.`rank`, 'C-','C','C+','B-','B','B+','A-','A','A+'), p.name ASC";

    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    /** @return list<Participant> */
    public function allByUser(int $userId, string $search = ''): array
    {
        $search = trim($search);
        $sql = 'SELECT ' . self::SELECT_FIELDS . ' FROM participants p WHERE p.user_id = :user_id';

        if ($search !== '') {
            $sql .= ' AND (p.name LIKE :search OR p.phone LIKE :search2 OR p.gms_source LIKE :search3)';
        }

        $sql .= ' ORDER BY ' . self::ORDER_BY_RANK;

        $statement = $this->db->prepare($sql);
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);

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

    /** @return list<Participant> */
    public function allBySession(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . '
             FROM participants p
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
    public function availableForSession(int $sessionId, int $userId, string $search = ''): array
    {
        $search = trim($search);
        $sql = 'SELECT ' . self::SELECT_FIELDS . '
                FROM participants p
                WHERE p.user_id = :user_id
                  AND NOT EXISTS (
                      SELECT 1 FROM session_participants sp
                      WHERE sp.participant_id = p.id AND sp.session_id = :session_id
                  )';

        if ($search !== '') {
            $sql .= ' AND (p.name LIKE :search OR p.phone LIKE :search2)';
        }

        $sql .= ' ORDER BY ' . self::ORDER_BY_RANK;

        $statement = $this->db->prepare($sql);
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('session_id', $sessionId, PDO::PARAM_INT);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $statement->bindValue('search', $like);
            $statement->bindValue('search2', $like);
        }

        $statement->execute();

        return array_map(
            static fn (array $row): Participant => Participant::fromRow($row),
            $statement->fetchAll(),
        );
    }

    public function find(int $id, int $userId): ?Participant
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . ' FROM participants p
             WHERE p.id = :id AND p.user_id = :user_id LIMIT 1'
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);

        $row = $statement->fetch();

        return $row ? Participant::fromRow($row) : null;
    }

    public function findInSession(int $id, int $sessionId): ?Participant
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::SELECT_FIELDS . '
             FROM participants p
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

        return $this->find((int) $this->db->lastInsertId(), $userId)
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

        return $statement->execute([
            'session_id' => $sessionId,
            'participant_id' => $participantId,
        ]);
    }

    public function unassignAllFromSession(int $sessionId): void
    {
        $statement = $this->db->prepare('DELETE FROM session_participants WHERE session_id = :session_id');
        $statement->execute(['session_id' => $sessionId]);
    }

    public function update(
        int $id,
        int $userId,
        string $name,
        string $rank,
        ?string $gender,
        ?string $phone,
        ?string $gmsSource,
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE participants SET name = :name, `rank` = :rank, gender = :gender, phone = :phone, gms_source = :gms_source
             WHERE id = :id AND user_id = :user_id'
        );

        return $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'rank' => $rank,
            'gender' => $gender ?: null,
            'phone' => $phone ?: null,
            'gms_source' => $gmsSource ?: null,
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        $statement = $this->db->prepare('DELETE FROM participants WHERE id = :id AND user_id = :user_id');

        return $statement->execute(['id' => $id, 'user_id' => $userId]);
    }

    public function countByUser(int $userId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM participants WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
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
    public function countByRankForUser(int $userId): array
    {
        $counts = array_fill_keys(Rank::LEVELS, 0);

        $statement = $this->db->prepare(
            'SELECT `rank`, COUNT(*) AS total FROM participants WHERE user_id = :user_id GROUP BY `rank`'
        );
        $statement->execute(['user_id' => $userId]);

        foreach ($statement->fetchAll() as $row) {
            $counts[$row['rank']] = (int) $row['total'];
        }

        return $counts;
    }
}
