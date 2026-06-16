-- Bagan (round) setup: count, pairing mode, court per match
ALTER TABLE sessions
    ADD COLUMN bagan_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER court_count,
    ADD COLUMN bagan_pairing_scope ENUM('global', 'per_bagan') NOT NULL DEFAULT 'global' AFTER bagan_count,
    ADD COLUMN bagan_pairing_mode ENUM('rank', 'gender') NOT NULL DEFAULT 'rank' AFTER bagan_pairing_scope,
    ADD COLUMN bagan_pairing_modes JSON NULL DEFAULT NULL AFTER bagan_pairing_mode;

ALTER TABLE matches
    ADD COLUMN court_number TINYINT UNSIGNED NULL DEFAULT NULL AFTER match_order;
