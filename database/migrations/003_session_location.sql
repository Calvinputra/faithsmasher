-- Add location to sessions
-- mysql -u root faithsmasher < database/migrations/003_session_location.sql

ALTER TABLE sessions
    ADD COLUMN location VARCHAR(255) NOT NULL DEFAULT '' AFTER session_date;
