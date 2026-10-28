#!/bin/sh
# Imports the PlayTube schema on first run and seeds the admin account.
# The bundled web installer (/install) requires a remote Envato purchase-code check,
# so the database is provisioned directly instead.
set -eu

MYSQL="mysql -h db -u root -p${MYSQL_ROOT_PASSWORD} --default-character-set=utf8mb4"

echo "Waiting for the config table..."
for i in $(seq 1 60); do
    if $MYSQL -Nse "SHOW TABLES LIKE 'config'" "$DB_NAME" | grep -q '^config$'; then
        break
    fi
    sleep 2
done

if ! $MYSQL -Nse "SHOW TABLES LIKE 'config'" "$DB_NAME" | grep -q '^config$'; then
    echo "Importing playtube.sql..."
    $MYSQL "$DB_NAME" < /seed/playtube.sql
fi

echo "Applying dev seed data..."
$MYSQL "$DB_NAME" < /seed/db-setup.sql

echo "Database ready."
