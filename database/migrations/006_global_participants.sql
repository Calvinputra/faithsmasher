-- Global participants per user + session assignment pivot

CREATE TABLE IF NOT EXISTS session_participants (
    session_id INT UNSIGNED NOT NULL,
    participant_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id, participant_id),
    KEY session_participants_participant_id_index (participant_id),
    CONSTRAINT session_participants_session_id_foreign FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
    CONSTRAINT session_participants_participant_id_foreign FOREIGN KEY (participant_id) REFERENCES participants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
