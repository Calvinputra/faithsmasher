-- Faith Smashers — full schema
-- mysql -u root faithsmasher < database/schema.sql

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('superadmin', 'admin') NOT NULL DEFAULT 'admin',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    session_date DATE NOT NULL,
    location VARCHAR(255) NOT NULL DEFAULT '',
    court_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    KEY sessions_user_id_index (user_id),
    KEY sessions_updated_by_user_id_index (updated_by_user_id),
    CONSTRAINT sessions_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT sessions_updated_by_user_id_foreign FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    `rank` ENUM('C-','C','C+','B-','B','B+','A-','A','A+') NOT NULL,
    gender ENUM('male','female','other') NULL DEFAULT NULL,
    phone VARCHAR(30) NULL DEFAULT NULL,
    gms_source VARCHAR(150) NULL DEFAULT NULL COMMENT 'Where participant knows GMS from',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    KEY participants_user_id_index (user_id),
    KEY participants_updated_by_user_id_index (updated_by_user_id),
    KEY participants_rank_index (`rank`),
    CONSTRAINT participants_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT participants_updated_by_user_id_foreign FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS session_participants (
    session_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id, participant_id),
    KEY session_participants_participant_id_index (participant_id),
    CONSTRAINT session_participants_session_id_foreign FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
    CONSTRAINT session_participants_participant_id_foreign FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    win_points INT UNSIGNED NOT NULL DEFAULT 21,
    lose_points INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY game_rules_session_id_index (session_id),
    CONSTRAINT game_rules_session_id_foreign FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    round_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    match_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
    participant1_id INT UNSIGNED NULL DEFAULT NULL,
    participant2_id INT UNSIGNED NULL DEFAULT NULL,
    status ENUM('pending','completed','bye') NOT NULL DEFAULT 'pending',
    score1 TINYINT UNSIGNED NULL DEFAULT NULL,
    score2 TINYINT UNSIGNED NULL DEFAULT NULL,
    winner_id INT UNSIGNED NULL DEFAULT NULL,
    is_manual TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY matches_session_id_index (session_id),
    CONSTRAINT matches_session_id_foreign FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
    CONSTRAINT matches_participant1_foreign FOREIGN KEY (participant1_id) REFERENCES participants (id) ON DELETE SET NULL,
    CONSTRAINT matches_participant2_foreign FOREIGN KEY (participant2_id) REFERENCES participants (id) ON DELETE SET NULL,
    CONSTRAINT matches_winner_foreign FOREIGN KEY (winner_id) REFERENCES participants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
