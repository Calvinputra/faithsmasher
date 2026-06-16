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
}
