-- Operator runbook: create the Metabase read-only reporting role.
-- Run as a database superuser / owner against the application database.
-- The application never creates roles; do not put this in a migration.
--
-- Provisioning (`php artisan insights:provision`) is tested against
-- Metabase OSS v0.50+. Dashcard layout uses PUT /api/dashboard/{id}
-- (replaced POST /api/dashboard/{id}/cards in v0.49); there is no
-- compatibility fallback for older instances.
--
-- Usage (psql):
--   \set pw '''your-strong-password'''
--   \i docs/ops/metabase-ro-role.sql
--
-- Or substitute :'pw' manually before running.

CREATE ROLE metabase_ro LOGIN PASSWORD :'pw' CONNECTION LIMIT 5;

ALTER ROLE metabase_ro SET statement_timeout = '30s';
ALTER ROLE metabase_ro SET idle_in_transaction_session_timeout = '60s';

GRANT USAGE ON SCHEMA analytics TO metabase_ro;
GRANT SELECT ON ALL TABLES IN SCHEMA analytics TO metabase_ro;
ALTER DEFAULT PRIVILEGES IN SCHEMA analytics
    GRANT SELECT ON TABLES TO metabase_ro;

REVOKE ALL ON SCHEMA public FROM metabase_ro;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM metabase_ro;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM metabase_ro;
