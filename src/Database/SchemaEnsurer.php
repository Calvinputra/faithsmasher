<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

final class SchemaEnsurer
{
    public static function ensure(): void
    {
        try {
            $pdo = Connection::get();
        } catch (\Throwable) {
            return;
        }

        self::ensureSessionsLocation($pdo);
        self::ensureSessionsCourtCount($pdo);
        self::ensureUserRoleStatus($pdo);
        self::ensureGlobalParticipants($pdo);
    }

    private static function ensureSessionsCourtCount(PDO $pdo): void
    {
        try {
            $statement = $pdo->query("SHOW COLUMNS FROM sessions LIKE 'court_count'");

            if ($statement !== false && $statement->rowCount() === 0) {
                $pdo->exec(
                    'ALTER TABLE sessions ADD COLUMN court_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER location'
                );
            }
        } catch (PDOException) {
        }
    }

    private static function ensureSessionsLocation(PDO $pdo): void
    {
        try {
            $statement = $pdo->query("SHOW COLUMNS FROM sessions LIKE 'location'");

            if ($statement !== false && $statement->rowCount() === 0) {
                $pdo->exec(
                    "ALTER TABLE sessions ADD COLUMN location VARCHAR(255) NOT NULL DEFAULT '' AFTER session_date"
                );
            }
        } catch (PDOException) {
            // Table may not exist yet — run database/schema.sql manually
        }
    }

    private static function ensureUserRoleStatus(PDO $pdo): void
    {
        try {
            $statement = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");

            if ($statement !== false && $statement->rowCount() === 0) {
                $pdo->exec(
                    "ALTER TABLE users
                     ADD COLUMN role ENUM('superadmin', 'admin') NOT NULL DEFAULT 'admin' AFTER password,
                     ADD COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER role"
                );
                $pdo->exec("UPDATE users SET role = 'superadmin', status = 'approved'");
            }
        } catch (PDOException) {
        }
    }

    private static function ensureGlobalParticipants(PDO $pdo): void
    {
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS session_participants (
                    session_id INT UNSIGNED NOT NULL,
                    participant_id INT UNSIGNED NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (session_id, participant_id),
                    KEY session_participants_participant_id_index (participant_id),
                    CONSTRAINT session_participants_session_id_foreign FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
                    CONSTRAINT session_participants_participant_id_foreign FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } catch (PDOException) {
            return;
        }

        if (self::participantsUsesGlobalSchema($pdo)) {
            return;
        }

        $sessionColumn = $pdo->query("SHOW COLUMNS FROM participants LIKE 'session_id'");

        if ($sessionColumn === false || $sessionColumn->rowCount() === 0) {
            return;
        }

        try {
            $userColumn = $pdo->query("SHOW COLUMNS FROM participants LIKE 'user_id'");

            if ($userColumn !== false && $userColumn->rowCount() === 0) {
                $pdo->exec('ALTER TABLE participants ADD COLUMN user_id INT UNSIGNED NULL AFTER id');
            }

            $pdo->exec(
                'UPDATE participants p
                 INNER JOIN sessions s ON s.id = p.session_id
                 SET p.user_id = s.user_id
                 WHERE p.user_id IS NULL'
            );

            $pdo->exec(
                'INSERT IGNORE INTO session_participants (session_id, participant_id)
                 SELECT session_id, id FROM participants WHERE session_id IS NOT NULL'
            );

            try {
                $pdo->exec('ALTER TABLE participants DROP FOREIGN KEY participants_session_id_foreign');
            } catch (PDOException) {
            }

            $pdo->exec('ALTER TABLE participants DROP COLUMN session_id');
            $pdo->exec('ALTER TABLE participants MODIFY user_id INT UNSIGNED NOT NULL');

            try {
                $pdo->exec(
                    'ALTER TABLE participants
                     ADD CONSTRAINT participants_user_id_foreign
                     FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                );
            } catch (PDOException) {
            }
        } catch (PDOException $exception) {
            error_log('[faithsmasher] Schema migration failed (global participants): ' . $exception->getMessage());
        }
    }

    private static function participantsUsesGlobalSchema(PDO $pdo): bool
    {
        try {
            $userColumn = $pdo->query("SHOW COLUMNS FROM participants LIKE 'user_id'");
            $sessionColumn = $pdo->query("SHOW COLUMNS FROM participants LIKE 'session_id'");

            return $userColumn !== false
                && $userColumn->rowCount() > 0
                && ($sessionColumn === false || $sessionColumn->rowCount() === 0);
        } catch (PDOException) {
            return false;
        }
    }
}
