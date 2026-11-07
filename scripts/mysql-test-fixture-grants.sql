-- RifaCloud test-only fixture account.
--
-- This account is used ONLY by service integration tests that must create
-- temporary users, sessions, raffles and orders. It has permissions only on
-- `rifacloud_test`; it must never be granted anything on `rifacloud`.
--
-- Apply as root after replacing <TEST_FIXTURE_DB_PASSWORD> with the local-only
-- password stored in src/.env.testing (never commit that file):
--
--   docker compose exec -T mysql sh -c \
--     'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' \
--     < scripts/mysql-test-fixture-grants.sql

CREATE USER IF NOT EXISTS 'rifacloud_test_fixture'@'%'
    IDENTIFIED BY '<TEST_FIXTURE_DB_PASSWORD>';

ALTER USER 'rifacloud_test_fixture'@'%'
    IDENTIFIED BY '<TEST_FIXTURE_DB_PASSWORD>';

-- Test schema only. No production schema and no shared monolith user.
GRANT ALL PRIVILEGES ON `rifacloud_test`.* TO 'rifacloud_test_fixture'@'%';

FLUSH PRIVILEGES;
