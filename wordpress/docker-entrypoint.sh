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
managed_plugins="buddypress copai-bp-jitsi-sparring copai-meeting-registration copai-meetup-block"
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
    else
        echo "WordPress already installed; skipping core install."
    fi

    # Ensure managed plugins are active on every start (no-op when already active).
    # This picks up newly added managed plugins on existing installations.
    wp plugin activate buddypress copai-bp-jitsi-sparring copai-meeting-registration copai-meetup-block \
        --allow-root 2>/dev/null || true

    needs_flush=0

    # Pretty permalinks — required for CPT archives (/meetups/) and BuddyPress
    # routes (/activity/, /members/). WordPress defaults to plain (?p=123).
    current_perm=$(wp option get permalink_structure --allow-root 2>/dev/null)
    if [ -z "$current_perm" ] || [ "$current_perm" = "''" ]; then
        echo "Setting pretty permalinks..."
        wp option update permalink_structure '/%postname%/' --allow-root
        needs_flush=1
    fi

    # Enable BuddyPress Groups component and ensure its DB tables/pages exist.
    # Setting the bp-active-components option alone is NOT enough: BP creates
    # component tables only via bp_core_install() (which is what the admin UI
    # calls). Without that, "save group" from the frontend fails because
    # wp_bp_groups doesn't exist.
    has_groups=$(wp eval 'echo !empty(get_option("bp-active-components", [])["groups"]) ? "1" : "0";' \
                 --allow-root 2>/dev/null || true)
    groups_table=$(wp eval '
        global $wpdb;
        $t = $wpdb->prefix . "bp_groups";
        echo $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t ? "1" : "0";
    ' --allow-root 2>/dev/null || true)
    if [ "$has_groups" != "1" ] || [ "$groups_table" != "1" ]; then
        echo "Installing BuddyPress Groups component (option + tables + pages)..."
        wp eval '
            $current = (array) get_option("bp-active-components", []);
            if (empty($current)) {
                $current = [
                    "xprofile"      => 1,
                    "settings"      => 1,
                    "members"       => 1,
                    "activity"      => 1,
                    "notifications" => 1,
                ];
            }
            $current["groups"] = 1;
            update_option("bp-active-components", $current);

            if (function_exists("buddypress")) {
                // dbDelta lives in wp-admin/includes/upgrade.php and is NOT
                // autoloaded in CLI context. BP installers call it.
                require_once ABSPATH . "wp-admin/includes/upgrade.php";

                $dir = buddypress()->plugin_dir;
                foreach (["bp-core/admin/bp-core-admin-schema.php",
                          "bp-core/admin/bp-core-admin-functions.php"] as $rel) {
                    if (file_exists($dir . $rel)) { require_once $dir . $rel; }
                }
                if (function_exists("bp_core_install")) {
                    bp_core_install(["groups" => 1]);
                }
                if (function_exists("bp_core_add_page_mappings")) {
                    bp_core_add_page_mappings(["groups" => 1]);
                }
            }
        ' --allow-root 2>/dev/null
        needs_flush=1
    fi

    if [ "$needs_flush" = "1" ]; then
        wp rewrite flush --allow-root 2>/dev/null
    fi

    # Point the bp-jitsi-sparring plugin at the self-hosted Jitsi if it's still
    # using the public meet.jit.si default. User-customised values are preserved.
    # The `|| true` keeps set -e from killing the script when the option is
    # missing — wp-cli returns exit 1 on get-of-missing-option.
    if [ -n "${JITSI_HOST:-}" ]; then
        current_jitsi=$(wp option get bpjs_server_url --allow-root 2>/dev/null || true)
        if [ -z "$current_jitsi" ] || [ "$current_jitsi" = "https://meet.jit.si" ]; then
            echo "Pointing bpjs_server_url at https://${JITSI_HOST}..."
            wp option update bpjs_server_url "https://${JITSI_HOST}" --allow-root 2>/dev/null
        fi
    fi

    # Pre-configure default menu (idempotent). The menu shows up automatically
    # with classic themes assigned to the `primary` location; with block themes
    # (e.g. twentytwentyfive) it must be selected once in the Site Editor →
    # Navigation block. Non-critical: failures here must not kill the container.
    set +e
    menu_id=$(wp menu create "Hauptmenü" --porcelain --allow-root 2>/dev/null)
    if [ -z "$menu_id" ]; then
        menu_id=$(wp menu list --fields=term_id,name --format=csv --allow-root 2>/dev/null \
                  | awk -F',' 'NR>1 && $2=="Hauptmenü" {print $1; exit}')
    fi
    if [ -n "$menu_id" ]; then
        item_count=$(wp menu item list "$menu_id" --format=count --allow-root 2>/dev/null)
        if [ "${item_count:-0}" = "0" ]; then
            echo "Adding menu items..."
            wp menu item add-custom "$menu_id" "${WP_MENU_HOME_LABEL:-Startseite}"    "/"          --allow-root
            wp menu item add-custom "$menu_id" "${WP_MENU_MEETUPS_LABEL:-Meetups}"    "/meetups/"  --allow-root
            wp menu item add-custom "$menu_id" "${WP_MENU_MEMBERS_LABEL:-Mitglieder}" "/activity/" --allow-root
            wp menu location assign "$menu_id" primary --allow-root 2>/dev/null
        else
            # Self-heal: update legacy URL of the Mitglieder item (/members/ → /activity/).
            db_id=$(wp menu item list "$menu_id" --format=csv --fields=db_id,title --allow-root 2>/dev/null \
                    | awk -F',' 'NR>1 && $2 == "Mitglieder" {print $1; exit}')
            if [ -n "$db_id" ]; then
                current=$(wp post meta get "$db_id" _menu_item_url --allow-root 2>/dev/null)
                if [ "$current" = "/members/" ]; then
                    echo "Updating legacy Mitglieder URL to /activity/..."
                    wp menu item update "$db_id" --link="/activity/" --allow-root 2>/dev/null
                fi
            fi
        fi
    fi
    set -e
fi

exec "$@"
