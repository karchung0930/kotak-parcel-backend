#!/usr/bin/env bash
# Runs once, when the MySQL volume is first created (see compose.yaml): adds
# the database the tests use and lets the app user reach it.

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS kotak_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON kotak_testing.* TO '$MYSQL_USER'@'%';
SQL
