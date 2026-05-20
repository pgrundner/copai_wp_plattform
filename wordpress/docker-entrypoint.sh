#!/usr/bin/env bash
set -e

# 1) Populate /var/www/html from /usr/src/wordpress.
#    The upstream entrypoint won't do this when wp-content is a bind-mount
#    (it considers the dir non-empty). We copy everything except wp-content,
#    which is handled separately below.
if [ ! -d /var/www/html/wp-includes ]; then
    echo "Copying WordPress core to /var/www/html..."
    (cd /usr/src/wordpress && tar cf - --exclude=wp-content .) \
        | (cd /var/www/html && tar xf -)
fi

# Make sure config files end up in place too (upstream entrypoint normally
# generates wp-config.php from env vars — replicate that).
if [ ! -f /var/www/html/wp-config.php ] && [ -f /usr/src/wordpress/wp-config-docker.php ]; then
    cp /usr/src/wordpress/wp-config-docker.php /var/www/html/wp-config.php
fi

# 2) Ensure all standard wp-content subdirs exist
for d in themes plugins mu-plugins uploads upgrade languages; do
    mkdir -p "/var/www/html/wp-content/$d"
done

# 3) Sync managed plugins on every start so image rebuilds (= new plugin
#    versions) propagate even when wp-content is a bind-mount.
managed_plugins="buddypress copai-bp-jitsi-sparring copai-meeting-registration"
for p in $managed_plugins; do
    src="/usr/src/wordpress/wp-content/plugins/$p"
    dst="/var/www/html/wp-content/plugins/$p"
    if [ -d "$src" ]; then
        rsync -a --delete "$src/" "$dst/"
    fi
done

# Sync mu-plugins (always — they ship with the image)
rsync -a --delete /usr/src/wordpress/wp-content/mu-plugins/ /var/www/html/wp-content/mu-plugins/

# Sync default themes WITHOUT --delete so user-installed themes survive rebuilds
rsync -a /usr/src/wordpress/wp-content/themes/ /var/www/html/wp-content/themes/

# Ownership for www-data
chown -R www-data:www-data /var/www/html

# 3) Only the wp-php service (not wp-cron) bootstraps WP install.
#    Gated on COPAI_RUN_INSTALL=1 set in compose.
if [ "${COPAI_RUN_INSTALL:-0}" = "1" ]; then
    until mariadb-admin ping -h"$WORDPRESS_DB_HOST" \
            -u"$WORDPRESS_DB_USER" -p"$WORDPRESS_DB_PASSWORD" \
            --skip-ssl --silent 2>/dev/null; do
        echo "Waiting for wp-db..."
        sleep 2
    done

    cd /var/www/html
    if ! wp core is-installed --allow-root --skip-plugins --skip-themes 2>/dev/null; then
        echo "Installing WordPress..."
        wp core install --allow-root --skip-plugins --skip-themes \
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
