#!/usr/bin/env bash
# Розгортає локальний WordPress (SQLite, без MySQL) для E2E-тестів плагіна.
# Ядро WP і SQLite-драйвер беруться з npm-пакета @wp-playground/wordpress-builds,
# тож потрібен лише доступ до registry.npmjs.org і PHP 7.4+ з pdo_sqlite.
#
#   WP_DIR=.wp WP_URL=http://127.0.0.1:8899 bash tests/e2e/setup-wp.sh
set -euo pipefail
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
WP_DIR="${WP_DIR:-$PLUGIN_DIR/.wp}"
WP_URL="${WP_URL:-http://127.0.0.1:8899}"
BUILDS_VER=0.9.19

if [ ! -f "$WP_DIR/wp-load.php" ]; then
  tmp="$(mktemp -d)"
  (cd "$tmp" && npm pack "@wp-playground/wordpress-builds@$BUILDS_VER" --silent >/dev/null \
    && tar xzf "wp-playground-wordpress-builds-$BUILDS_VER.tgz" \
       package/src/wordpress/wp-6.5.zip \
       package/src/sqlite-database-integration/sqlite-database-integration.zip \
       package/public/wp-6.5)
  mkdir -p "$WP_DIR"
  unzip -q -o "$tmp/package/src/wordpress/wp-6.5.zip" -d "$WP_DIR"
  # статичні файли (jQuery, CSS тощо) у збірці Playground лежать окремо від PHP
  cp -a "$tmp/package/public/wp-6.5/." "$WP_DIR/"
  unzip -q -o "$tmp/package/src/sqlite-database-integration/sqlite-database-integration.zip" -d "$WP_DIR/wp-content/plugins"
  mv "$WP_DIR/wp-content/plugins/sqlite-database-integration-main" "$WP_DIR/wp-content/plugins/sqlite-database-integration"
  sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_DIR/wp-content/plugins/sqlite-database-integration#" \
      -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
      "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP_DIR/wp-content/db.php"
  rm -rf "$tmp"
fi

cat > "$WP_DIR/wp-config.php" <<PHP
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', 'e2e.sqlite' );
define( 'WP_HOME', '$WP_URL' ); define( 'WP_SITEURL', '$WP_URL' );
define( 'AUTH_KEY', 'e2e' ); define( 'SECURE_AUTH_KEY', 'e2e' ); define( 'LOGGED_IN_KEY', 'e2e' ); define( 'NONCE_KEY', 'e2e' );
define( 'AUTH_SALT', 'e2e' ); define( 'SECURE_AUTH_SALT', 'e2e' ); define( 'LOGGED_IN_SALT', 'e2e' ); define( 'NONCE_SALT', 'e2e' );
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true ); define( 'WP_HTTP_BLOCK_EXTERNAL', true );
\$table_prefix = 'wp_';
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
PHP

mkdir -p "$WP_DIR/wp-content/mu-plugins" "$WP_DIR/wp-content/database"
ln -sfn "$PLUGIN_DIR" "$WP_DIR/wp-content/plugins/bp-attribution"
cp "$PLUGIN_DIR/tests/e2e/fixture/bp-e2e-fixture.php" "$WP_DIR/wp-content/mu-plugins/"
cp "$PLUGIN_DIR/tests/e2e/fixture/router.php" "$WP_DIR/router.php"
rm -f "$WP_DIR/wp-content/database/e2e.sqlite"
php "$PLUGIN_DIR/tests/e2e/fixture/install.php" "$WP_DIR"
echo "WordPress готовий: $WP_URL  (php -S ${WP_URL#http://} -t $WP_DIR $WP_DIR/router.php)"
