-- Creates or rebuilds the users table.
--
-- The brief asks for a way to "create/rebuild" the table, so this drops any
-- existing table first: running --create-table twice must leave a clean table
-- rather than silently no-op. This DESTROYS existing rows by design.

DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    surname    VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
