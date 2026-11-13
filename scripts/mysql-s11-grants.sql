-- S11 grants for the consent evidence table.
--
-- Apply ONLY after the `user_consents` migration has been authorized and
-- applied to the schema. auth-service is the sole writer of `users` and
-- `user_consents`; no other service needs access to consent evidence.
--
-- This file is a template. Do not run it on the main server without explicit
-- authorization. `rifacloud_test` is included because the integration suite
-- uses the same auth-service image against the test schema.
--
-- Applied to the local `rifacloud` and `rifacloud_test` schemas on 2026-11-12
-- after explicit authorization; verified with SHOW GRANTS.

GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`user_consents` TO 'rifacloud_auth'@'%';

-- Test schema used by the E2E and auth-service integration suites.
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud_test`.`user_consents` TO 'rifacloud_auth'@'%';

FLUSH PRIVILEGES;
