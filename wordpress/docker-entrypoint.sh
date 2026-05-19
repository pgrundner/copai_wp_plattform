#!/usr/bin/env bash
set -e

# 1) Upstream entrypoint copies /usr/src/wordpress → /var/www/html
#    if /var/www/html is empty. We run it with `true` so its file-copy
#    work completes without execing php-fpm yet — we exec at the end.
if [ -f /usr/local/bin/docker-entrypoint.sh ]; then
    /usr/local/bin/docker-entrypoint.sh true
fi

# 2) Sync managed plugins on every start so image rebuilds (= new plugin
#    versions) propagate even when wp-content is a bind-mount.
managed_plugins="buddypress copai-bp-jitsi-sparring copai-meeting-registration"
for p in $managed_plugins; do
    src="/usr/src/wordpress/wp-content/plugins/$p"
    dst="/var/www/html/wp-content/plugins/$p"
    if [ -d "$src" ]; then
        mkdir -p "$dst"
        rsync -a --delete "$src/" "$dst/"
    fi
done

# Sync mu-plugins (always — they ship with the image)
mkdir -p /var/www/html/wp-content/mu-plugins
rsync -a --delete /usr/src/wordpress/wp-content/mu-plugins/ /var/www/html/wp-content/mu-plugins/

# Ownership for www-data
chown -R www-data:www-data /var/www/html/wp-content

# Only the wp-php service (not wp-cron) needs to bootstrap WP install.
# We gate on COPAI_RUN_INSTALL=1 set by compose.
if [ "${COPAI_RUN_INSTALL:-0}" = "1" ]; then
    until mariadb-admin ping -h"$WORDPRESS_DB_HOST" \
            -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" --silent 2>/dev/null; do
        echo "Waiting for wp-db..."
        sleep 2
    done

    cd /var/www/html
    if ! wp core is-installed --allow-root 2>/dev/null; then
        echo "Installing WordPress..."
        wp core install --allow-root \
            --url="https://${WP_HOST}" \
            --title="${WP_TITLE}" \
            --admin_user="${WP_ADMIN_USER}" \
            --admin_password="${WP_ADMIN_PASS}" \
            --admin_email="${WP_ADMIN_EMAIL}" \
            --skip-email
        wp plugin activate buddypress copai-bp-jitsi-sparring copai-meeting-registration \
            --allow-root || true
    else
        echo "WordPress already installed; skipping core install."
    fi
fi

exec "$@"
