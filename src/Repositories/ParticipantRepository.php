<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\Participant;
use App\Support\Rank;
use PDO;

final class ParticipantRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    /** @return list<Participant> */
    public function allBySession(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, session_id, name, `rank`, gender, phone, gms_source, created_at
             FROM participants WHERE session_id = :session_id
             ORDER BY FIELD(`rank`, \'C-\',\'C\',\'C+\',\'B-\',\'B\',\'B+\',\'A-\',\'A\',\'A+\'), name ASC'
        );
        $statement->execute(['session_id' => $sessionId]);

        return array_map(
            static fn (array $row): Participant => Participant::fromRow($row),
            $statement->fetchAll()
        );
    }

    public function find(int $id, int $sessionId): ?Participant
    {
        $statement = $this->db->prepare(
            'SELECT id, session_id, name, `rank`, gender, phone, gms_source, created_at
             FROM participants WHERE id = :id AND session_id = :session_id LIMIT 1'
        );
        $statement->execute(['id' => $id, 'session_id' => $sessionId]);

        $row = $statement->fetch();

        return $row ? Participant::fromRow($row) : null;
    }

    public function create(
        int $sessionId,
        string $name,
        string $rank,
        ?string $gender,
        ?string $phone,
        ?string $gmsSource,
    ): Participant {
        $statement = $this->db->prepare(
            'INSERT INTO participants (session_id, name, `rank`, gender, phone, gms_source)
             VALUES (:session_id, :name, :rank, :gender, :phone, :gms_source)'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'name' => $name,
            'rank' => $rank,
            'gender' => $gender ?: null,
            'phone' => $phone ?: null,
            'gms_source' => $gmsSource ?: null,
        ]);

        return $this->find((int) $this->db->lastInsertId(), $sessionId)
            ?? throw new \RuntimeException('Failed to create participant.');
    }

    public function update(
        int $id,
        int $sessionId,
        string $name,
        string $rank,
        ?string $gender,
        ?string $phone,
        ?string $gmsSource,
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE participants SET name = :name, `rank` = :rank, gender = :gender, phone = :phone, gms_source = :gms_source
             WHERE id = :id AND session_id = :session_id'
        );

        return $statement->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'name' => $name,
            'rank' => $rank,
            'gender' => $gender ?: null,
            'phone' => $phone ?: null,
            'gms_source' => $gmsSource ?: null,
        ]);
    }

    public function delete(int $id, int $sessionId): bool
    {
        $statement = $this->db->prepare('DELETE FROM participants WHERE id = :id AND session_id = :session_id');

        return $statement->execute(['id' => $id, 'session_id' => $sessionId]);
    }

    public function countBySession(int $sessionId): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM participants WHERE session_id = :session_id');
        $statement->execute(['session_id' => $sessionId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    public function countByRank(int $sessionId): array
    {
        $counts = array_fill_keys(Rank::LEVELS, 0);

        $statement = $this->db->prepare(
            'SELECT `rank`, COUNT(*) AS total FROM participants WHERE session_id = :session_id GROUP BY `rank`'
        );
        $statement->execute(['session_id' => $sessionId]);

        foreach ($statement->fetchAll() as $row) {
            $counts[$row['rank']] = (int) $row['total'];
        }

        return $counts;
    }
}
