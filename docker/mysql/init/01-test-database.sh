#!/bin/bash
# Runs once, when the MySQL data volume is first initialised.
#
# The image grants MYSQL_USER rights on MYSQL_DATABASE only. The test suite uses
# `${MYSQL_DATABASE}_test` (doctrine `dbname_suffix`), which used to mean running
# the tests as root. Creating the test database here and granting it to the
# application user keeps root out of the application entirely.
set -euo pipefail

test_db="${MYSQL_DATABASE}_test"

mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${test_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${test_db}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
