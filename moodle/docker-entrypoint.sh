#!/bin/bash
set -e

echo "Konfiguriere PHP Upload Limit auf: $MOODLE_MAX_UPLOAD"

cat <<EOF > /usr/local/etc/php/conf.d/moodle-uploads.ini
file_uploads = On
memory_limit = $MOODLE_MAX_UPLOAD
upload_max_filesize = $MOODLE_MAX_UPLOAD
post_max_size = $MOODLE_MAX_UPLOAD
max_execution_time = 600
EOF

# 1. Warten auf die Datenbank
echo "Warte auf Datenbank-Verbindung..."

# WICHTIGE ÄNDERUNG: Wir nutzen getenv() in PHP. Das ist viel sicherer gegen Sonderzeichen!
until php -r "
    try {
        new PDO(
            'mysql:host='.getenv('MOODLE_DB_HOST').';dbname='.getenv('MOODLE_DB_NAME'),
            getenv('MOODLE_DB_USER'),
            getenv('MOODLE_DB_PASSWORD')
        );
    } catch (PDOException \$e) {
        fwrite(STDERR, 'Verbindung fehlgeschlagen. '); 
        exit(1);
    }
" > /dev/null 2>&1; do
  echo "Datenbank noch nicht bereit - warte 5 Sekunden..."
  sleep 5
done
echo "Datenbank ist bereit!"

# 2. Prüfen, ob die Datenbank Tabellen enthält
# Auch hier nutzen wir getenv()
DB_CHECK=$(php -r "
    try {
        \$dbh = new PDO(
            'mysql:host='.getenv('MOODLE_DB_HOST').';dbname='.getenv('MOODLE_DB_NAME'),
            getenv('MOODLE_DB_USER'),
            getenv('MOODLE_DB_PASSWORD')
        );
        \$stmt = \$dbh->query('SELECT count(*) FROM mdl_config');
        if (\$stmt) echo 'INSTALLED'; else echo 'EMPTY';
    } catch (Exception \$e) {
        echo 'EMPTY';
    }
")

if [ "$DB_CHECK" = "EMPTY" ]; then
    echo "Datenbank ist leer. Führe Installation durch..."

    # Fall A: config.php existiert schon
    if [ -f "/var/www/html/config.php" ]; then
        echo "config.php gefunden. Installiere nur die Datenbank..."
        php admin/cli/install_database.php \
            --lang=de \
            --adminuser=$MOODLE_ADMIN_USER \
            --adminpass=$MOODLE_ADMIN_PASS \
            --adminemail=$MOODLE_ADMIN_EMAIL \
            --agree-license

    # Fall B: Ganz frischer Start ohne config.php
    else
        echo "Keine config.php. Führe Voll-Installation aus..."
        php admin/cli/install.php \
            --lang=de \
            --wwwroot=$MOODLE_URL \
            --dataroot=/var/www/moodledata \
            --dbtype=mariadb \
            --dbhost=$MOODLE_DB_HOST \
            --dbname=$MOODLE_DB_NAME \
            --dbuser=$MOODLE_DB_USER \
            --dbpass=$MOODLE_DB_PASSWORD \
            --fullname="Mein Docker Moodle" \
            --shortname="Moodle" \
            --adminuser=$MOODLE_ADMIN_USER \
            --adminpass=$MOODLE_ADMIN_PASS \
            --adminemail=$MOODLE_ADMIN_EMAIL \
            --non-interactive \
            --agree-license

        chown www-data:www-data /var/www/html/config.php
        chown -R www-data:www-data /var/www/moodledata

    fi

    echo "Installation abgeschlossen!"
else
    echo "Moodle ist bereits installiert (Datenbank gefüllt). Starte normal."
fi

# 3. OAuth2-Issuer einrichten (idempotent — prüft auf existing issuer by name).
#    Nur wenn das Setup-Script gemountet und die Variablen gesetzt sind.
if [ -f /var/www/html/setup_oauth2.php ] && [ -n "${OAUTH_CLIENT_SECRET:-}" ]; then
    echo "Konfiguriere OAuth2-SSO..."
    php /var/www/html/setup_oauth2.php || echo "OAuth2 setup failed (non-fatal)."
fi

# 4. Server starten
exec "$@"