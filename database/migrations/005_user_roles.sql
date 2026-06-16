-- User roles & approval status
-- mysql -u root faithsmasher < database/migrations/005_user_roles.sql

ALTER TABLE users
    ADD COLUMN role ENUM('superadmin', 'admin') NOT NULL DEFAULT 'admin' AFTER password,
    ADD COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER role;

-- Existing users become approved superadmin (adjust manually if needed)
UPDATE users SET role = 'superadmin', status = 'approved';
