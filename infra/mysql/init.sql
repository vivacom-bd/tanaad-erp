-- Runs once on first start of the MySQL volume.
CREATE DATABASE IF NOT EXISTS tanaad_test;
GRANT ALL PRIVILEGES ON tanaad_test.* TO 'tanaad'@'%';
FLUSH PRIVILEGES;
