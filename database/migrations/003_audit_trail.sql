-- Audit trail: track who last updated sessions & participants
-- mysql -u root faithsmasher < database/migrations/003_audit_trail.sql

ALTER TABLE sessions
    ADD COLUMN updated_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER updated_at;

ALTER TABLE participants
    ADD COLUMN updated_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER updated_at;
