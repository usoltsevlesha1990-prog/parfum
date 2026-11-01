#!/bin/sh
# Cloud workspace helper: only starts the already initialized local DB.
set -eu
PARFUM_RUNTIME=/workspace/.parfum-runtime
export LD_LIBRARY_PATH="$PARFUM_RUNTIME/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
if [ ! -d "$PARFUM_RUNTIME/data/mysql" ]; then
    echo 'Local MariaDB data directory is not initialized.' >&2
    exit 1
fi
if "$PARFUM_RUNTIME/root/usr/bin/mariadb-admin" --no-defaults --socket="$PARFUM_RUNTIME/mysql.sock" -u root ping >/dev/null 2>&1; then
    echo 'Local MariaDB already running.'
    exit 0
fi
exec "$PARFUM_RUNTIME/root/usr/sbin/mariadbd" --no-defaults \
    --basedir="$PARFUM_RUNTIME/root/usr" --datadir="$PARFUM_RUNTIME/data" \
    --socket="$PARFUM_RUNTIME/mysql.sock" --pid-file="$PARFUM_RUNTIME/mysql.pid" \
    --skip-networking --log-error="$PARFUM_RUNTIME/mysql.log"
