-- Local development only: separate schema for the automated test suite.
-- Runs once, when the MySQL data volume is first initialized.
CREATE DATABASE IF NOT EXISTS `rifacloud_test`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `rifacloud_test`.* TO 'rifacloud'@'%';
FLUSH PRIVILEGES;
