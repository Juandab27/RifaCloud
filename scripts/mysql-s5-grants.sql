-- RifaCloud S5 - separacion minima de permisos MySQL por microservicio.
--
-- PLANTILLA: sustituye los <..._PASSWORD> por secretos reales desde el gestor de
-- secretos / .env raiz. No commitees este archivo con contrasenas.
--
-- Diseno validado el 2026-11-06 en el esquema rifacloud_test con usuarios
-- temporales (ver docs/sdd/02-technical-design.md seccion 9):
--
--  * Cada servicio solo escribe sus tablas.
--  * Los servicios que necesitan bloquear filas de otro dominio usan
--    SELECT ... FOR UPDATE. MySQL 8.4 exige el privilegio UPDATE para una
--    lectura con bloqueo; se concede UPDATE solo sobre la columna inofensiva
--    updated_at, de modo que el servicio no puede cambiar columnas de negocio.
--  * El monolito (usuario rifacloud) conserva ALL hasta retirar el codigo de
--    rollback; no se toca en este paso.
--
-- Aplicar como root y solo tras autorizacion explicita:
--   docker compose exec -T mysql sh -c \
--     'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < /scripts/mysql-s5-grants.sql'

-- ---------------------------------------------------------------------------
-- auth-service: usuarios, sesiones y cache de throttling.
-- ---------------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'rifacloud_auth'@'%' IDENTIFIED BY '<AUTH_DB_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.users TO 'rifacloud_auth'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.sessions TO 'rifacloud_auth'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.cache TO 'rifacloud_auth'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.cache_locks TO 'rifacloud_auth'@'%';

-- ---------------------------------------------------------------------------
-- raffles-service: rifas y generacion de numeros; lee identidad para admin.
-- ---------------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'rifacloud_raffles'@'%' IDENTIFIED BY '<RAFFLES_DB_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.raffles TO 'rifacloud_raffles'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.raffle_numbers TO 'rifacloud_raffles'@'%';
GRANT SELECT ON rifacloud.users TO 'rifacloud_raffles'@'%';
GRANT SELECT ON rifacloud.sessions TO 'rifacloud_raffles'@'%';

-- ---------------------------------------------------------------------------
-- orders-service: ordenes, items, reserva/venta/liberacion de numeros.
-- ---------------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'rifacloud_orders'@'%' IDENTIFIED BY '<ORDERS_DB_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.orders TO 'rifacloud_orders'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.order_items TO 'rifacloud_orders'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.raffle_numbers TO 'rifacloud_orders'@'%';
GRANT SELECT ON rifacloud.raffles TO 'rifacloud_orders'@'%';
GRANT SELECT ON rifacloud.payments TO 'rifacloud_orders'@'%';
GRANT SELECT ON rifacloud.users TO 'rifacloud_orders'@'%';
GRANT SELECT ON rifacloud.sessions TO 'rifacloud_orders'@'%';
-- Necesario para SELECT ... FOR UPDATE sobre raffles/payments (MySQL 8.4).
GRANT UPDATE (updated_at) ON rifacloud.raffles TO 'rifacloud_orders'@'%';
GRANT UPDATE (updated_at) ON rifacloud.payments TO 'rifacloud_orders'@'%';

-- ---------------------------------------------------------------------------
-- payments-service: pagos y eventos del proveedor; lee la orden para el
-- contrato OrderResource y para bloquear el checkout por orden.
-- ---------------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'rifacloud_payments'@'%' IDENTIFIED BY '<PAYMENTS_DB_PASSWORD>';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.payments TO 'rifacloud_payments'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON rifacloud.payment_webhook_events TO 'rifacloud_payments'@'%';
GRANT SELECT ON rifacloud.orders TO 'rifacloud_payments'@'%';
GRANT SELECT ON rifacloud.order_items TO 'rifacloud_payments'@'%';
GRANT SELECT ON rifacloud.raffle_numbers TO 'rifacloud_payments'@'%';
GRANT SELECT ON rifacloud.raffles TO 'rifacloud_payments'@'%';
GRANT SELECT ON rifacloud.users TO 'rifacloud_payments'@'%';
GRANT SELECT ON rifacloud.sessions TO 'rifacloud_payments'@'%';
-- Necesario para SELECT ... FOR UPDATE sobre orders (MySQL 8.4).
GRANT UPDATE (updated_at) ON rifacloud.orders TO 'rifacloud_payments'@'%';

-- ---------------------------------------------------------------------------
-- Verificacion (no modifica datos):
-- ---------------------------------------------------------------------------
SHOW GRANTS FOR 'rifacloud_auth'@'%';
SHOW GRANTS FOR 'rifacloud_raffles'@'%';
SHOW GRANTS FOR 'rifacloud_orders'@'%';
SHOW GRANTS FOR 'rifacloud_payments'@'%';

-- Pruebas negativas esperadas (deben devolver "command denied"):
--   UPDATE rifacloud.payments ...  como rifacloud_orders
--   UPDATE rifacloud.orders   ...  como rifacloud_payments
--   SELECT rifacloud.payments ...  como rifacloud_auth
--
-- Rollback de los usuarios (solo tras autorizacion):
--   DROP USER IF EXISTS 'rifacloud_auth'@'%', 'rifacloud_raffles'@'%',
--     'rifacloud_orders'@'%', 'rifacloud_payments'@'%';
--   (Despues, restaurar DB_USERNAME/DB_PASSWORD compartidos en Compose.)
