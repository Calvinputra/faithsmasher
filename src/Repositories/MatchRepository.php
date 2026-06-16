<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\TournamentMatch;
use PDO;

final class MatchRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    /** @return list<TournamentMatch> */
    public function allBySession(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, session_id, round_number, match_order, participant1_id, participant2_id,
                    status, score1, score2, winner_id, is_manual
             FROM matches WHERE session_id = :session_id
             ORDER BY round_number ASC, match_order ASC'
        );
        $statement->execute(['session_id' => $sessionId]);

        return array_map(
            static fn (array $row): TournamentMatch => TournamentMatch::fromRow($row),
            $statement->fetchAll()
        );
    }

    public function deleteBySession(int $sessionId): void
    {
        $statement = $this->db->prepare('DELETE FROM matches WHERE session_id = :session_id');
        $statement->execute(['session_id' => $sessionId]);
    }

    public function create(
        int $sessionId,
        int $roundNumber,
        int $matchOrder,
        ?int $participant1Id,
        ?int $participant2Id,
        bool $isManual = false,
        string $status = 'pending',
    ): TournamentMatch {
        $statement = $this->db->prepare(
            'INSERT INTO matches (session_id, round_number, match_order, participant1_id, participant2_id, is_manual, status)
             VALUES (:session_id, :round_number, :match_order, :p1, :p2, :is_manual, :status)'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'round_number' => $roundNumber,
            'match_order' => $matchOrder,
            'p1' => $participant1Id,
            'p2' => $participant2Id,
            'is_manual' => $isManual ? 1 : 0,
            'status' => $status,
        ]);

        $id = (int) $this->db->lastInsertId();
        $matches = $this->allBySession($sessionId);

        foreach ($matches as $match) {
            if ($match->id === $id) {
                return $match;
            }
        }

        throw new \RuntimeException('Failed to create match.');
    }

    public function updatePairing(
        int $matchId,
        int $sessionId,
        ?int $participant1Id,
        ?int $participant2Id,
    ): bool {
        $statement = $this->db->prepare(
            'UPDATE matches SET participant1_id = :p1, participant2_id = :p2, is_manual = 1
             WHERE id = :id AND session_id = :session_id'
        );

        return $statement->execute([
            'id' => $matchId,
            'session_id' => $sessionId,
            'p1' => $participant1Id,
            'p2' => $participant2Id,
        ]);
    }

    /** @return array<int, int> participant_id => match count */
    public function playerMatchCounts(int $sessionId): array
    {
        $counts = [];
        $statement = $this->db->prepare(
            'SELECT participant1_id AS pid FROM matches WHERE session_id = :session_id AND participant1_id IS NOT NULL
             UNION ALL
             SELECT participant2_id AS pid FROM matches WHERE session_id = :session_id2 AND participant2_id IS NOT NULL'
        );
        $statement->execute(['session_id' => $sessionId, 'session_id2' => $sessionId]);

        foreach ($statement->fetchAll() as $row) {
            $pid = (int) $row['pid'];
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<array{match: TournamentMatch, p1: ?array<string, mixed>, p2: ?array<string, mixed>}>
     */
    public function allWithParticipants(int $sessionId): array
    {
        $statement = $this->db->prepare(
            'SELECT m.id, m.session_id, m.round_number, m.match_order, m.participant1_id, m.participant2_id,
                    m.status, m.score1, m.score2, m.winner_id, m.is_manual,
                    p1.name AS p1_name, p1.`rank` AS p1_rank,
                    p2.name AS p2_name, p2.`rank` AS p2_rank
             FROM matches m
             LEFT JOIN participants p1 ON p1.id = m.participant1_id
             LEFT JOIN participants p2 ON p2.id = m.participant2_id
             WHERE m.session_id = :session_id
             ORDER BY m.round_number ASC, m.match_order ASC'
        );
        $statement->execute(['session_id' => $sessionId]);

        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $match = TournamentMatch::fromRow($row);
            $result[] = [
                'match' => $match,
                'p1' => $row['p1_name'] ? ['name' => $row['p1_name'], 'rank' => $row['p1_rank']] : null,
                'p2' => $row['p2_name'] ? ['name' => $row['p2_name'], 'rank' => $row['p2_rank']] : null,
            ];
        }

        return $result;
    }
}
