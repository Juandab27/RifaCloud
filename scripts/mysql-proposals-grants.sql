-- RifaCloud S13 proposal grants.
--
-- raffles-service is the sole writer of `raffle_proposals` and already reads
-- `users`/`sessions` with least-privilege SELECT. Apply this file ONLY after
-- the `2026_11_15_000014_create_raffle_proposals_table` migration has been
-- authorized and applied to the target schema.
--
-- For the main schema, run the `rifacloud` statements with explicit
-- authorization. The `rifacloud_test` statements are what the local
-- integration suite needs.

-- Main schema (requires explicit authorization and a verified backup).
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`raffle_proposals` TO 'rifacloud_raffles'@'%';

-- Test schema used by the integration and E2E suites.
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud_test`.`raffle_proposals` TO 'rifacloud_raffles'@'%';

FLUSH PRIVILEGES;
