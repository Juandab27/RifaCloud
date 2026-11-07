-- RifaCloud production grants (main schema only).
--
-- Apply after the 13 migrations with:
--   deploy/aws/bootstrap-db.sh
--
-- Passwords are created by bootstrap-db.sh from the root .env. This file
-- contains no credentials and is safe to version.

-- Shared monolith/rollback user. Retained until the rollback path is retired.
GRANT ALL PRIVILEGES ON `rifacloud`.* TO 'rifacloud'@'%';

-- auth-service: users, sessions and cache. No business data.
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`users` TO 'rifacloud_auth'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`sessions` TO 'rifacloud_auth'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`cache` TO 'rifacloud_auth'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`cache_locks` TO 'rifacloud_auth'@'%';

-- raffles-service: raffle administration, number creation and results.
-- It reads users/sessions only for the shared admin session.
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`raffles` TO 'rifacloud_raffles'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`raffle_numbers` TO 'rifacloud_raffles'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`raffle_results` TO 'rifacloud_raffles'@'%';
GRANT SELECT ON `rifacloud`.`users` TO 'rifacloud_raffles'@'%';
GRANT SELECT ON `rifacloud`.`sessions` TO 'rifacloud_raffles'@'%';

-- orders-service: orders, items, reservations and expiry. Payments are read
-- only; raffles can only refresh updated_at.
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`orders` TO 'rifacloud_orders'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`order_items` TO 'rifacloud_orders'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`raffle_numbers` TO 'rifacloud_orders'@'%';
GRANT SELECT, UPDATE (`updated_at`) ON `rifacloud`.`payments` TO 'rifacloud_orders'@'%';
GRANT SELECT, UPDATE (`updated_at`) ON `rifacloud`.`raffles` TO 'rifacloud_orders'@'%';
GRANT SELECT ON `rifacloud`.`users` TO 'rifacloud_orders'@'%';
GRANT SELECT ON `rifacloud`.`sessions` TO 'rifacloud_orders'@'%';

-- payments-service: payments and webhook audit. Orders are read only except
-- updated_at; order/number state is changed by orders-service.
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`payments` TO 'rifacloud_payments'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `rifacloud`.`payment_webhook_events` TO 'rifacloud_payments'@'%';
GRANT SELECT, UPDATE (`updated_at`) ON `rifacloud`.`orders` TO 'rifacloud_payments'@'%';
GRANT SELECT ON `rifacloud`.`order_items` TO 'rifacloud_payments'@'%';
GRANT SELECT ON `rifacloud`.`raffle_numbers` TO 'rifacloud_payments'@'%';
GRANT SELECT ON `rifacloud`.`raffles` TO 'rifacloud_payments'@'%';
GRANT SELECT ON `rifacloud`.`users` TO 'rifacloud_payments'@'%';
GRANT SELECT ON `rifacloud`.`sessions` TO 'rifacloud_payments'@'%';

FLUSH PRIVILEGES;
