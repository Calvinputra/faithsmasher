ALTER TABLE sessions
    ADD COLUMN bagan_share_token VARCHAR(32) NULL DEFAULT NULL AFTER bagan_pairing_modes,
    ADD UNIQUE KEY sessions_bagan_share_token_unique (bagan_share_token);
