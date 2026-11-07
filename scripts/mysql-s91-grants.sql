-- S9.1 grants for the FR-014 result table.
--
-- Apply ONLY after the `raffle_results` migration has been authorized and
-- applied to the schema. raffles-service is the sole writer; no other service
-- needs access to this table.
--
-- The notifications table is owned by the scheduler/notification process and
-- is used through the existing shared monolith user in the transitional setup;
-- it requires no new per-service grant.

GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`raffle_results` TO 'rifacloud_raffles'@'%';

-- Test schema used by the E2E suites.
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud_test`.`raffle_results` TO 'rifacloud_raffles'@'%';

FLUSH PRIVILEGES;
