#!/bin/bash
set -eu
mysql <<'SQL'
GRANT ALL PRIVILEGES ON online_parser_siteaacess_testing.* TO 'sadavod'@'localhost';
GRANT ALL PRIVILEGES ON online_parser_siteaacess_testing.* TO 'sadavod'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
