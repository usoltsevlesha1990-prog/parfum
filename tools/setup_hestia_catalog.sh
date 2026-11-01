#!/bin/bash
# Run once as root on the Hestia server. Creates a NEW DB, never alters existing DBs.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo 'Run as root on the Hestia server.' >&2; exit 1; }
PARFUM_REVISION=${1:?Pass the reviewed full Git commit SHA}
[[ "$PARFUM_REVISION" =~ ^[0-9a-f]{40}$ ]] || exit 1
PARFUM_USER=parfumplace
PARFUM_CONFIG=/home/parfumplace/.config/parfumplace/database.php
[ ! -e "$PARFUM_CONFIG" ] || { echo 'Database config already exists; refusing to overwrite.' >&2; exit 1; }
command -v php >/dev/null
php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'
/usr/local/hestia/bin/v-list-databases "$PARFUM_USER" json > /tmp/parfum-db-list.$$
trap 'rm -f /tmp/parfum-db-list.$$' EXIT
php -r '$x=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);exit(isset($x["parfumplace_catalog"]) ? 1 : 0);' /tmp/parfum-db-list.$$ || { echo 'Database already exists; refusing to change it.' >&2; exit 1; }
PARFUM_WORK=$(mktemp -d /root/parfum-catalog.XXXXXX)
chmod 700 "$PARFUM_WORK"
mkdir -p "$PARFUM_WORK/tools" "$PARFUM_WORK/bootstrap" "$PARFUM_WORK/tests/fixtures"
for PARFUM_FILE in tools/import_mysql_catalog.php bootstrap/catalog_schema.php tests/fixtures/catalog.json; do
    curl --fail --silent --show-error --proto '=https' --tlsv1.2 "https://raw.githubusercontent.com/usoltsevlesha1990-prog/parfum/$PARFUM_REVISION/$PARFUM_FILE" -o "$PARFUM_WORK/$PARFUM_FILE"
done
PARFUM_PASSWORD=$(openssl rand -hex 24)
/usr/local/hestia/bin/v-add-database "$PARFUM_USER" catalog catalog "$PARFUM_PASSWORD" mysql localhost utf8mb4
install -d -m 700 -o "$PARFUM_USER" -g "$PARFUM_USER" /home/parfumplace/.config/parfumplace
umask 077
# The random password contains hex only; it is never printed or sent to GitHub.
printf "<?php\nreturn ['host'=>'127.0.0.1','name'=>'parfumplace_catalog','user'=>'parfumplace_catalog','password'=>'%s'];\n" "$PARFUM_PASSWORD" > "$PARFUM_CONFIG"
chown "$PARFUM_USER:$PARFUM_USER" "$PARFUM_CONFIG"
chmod 600 "$PARFUM_CONFIG"
unset PARFUM_PASSWORD
php "$PARFUM_WORK/tools/import_mysql_catalog.php" "$PARFUM_CONFIG"
echo 'READY: new MySQL catalog created and imported. Credentials stored outside public_html.'
