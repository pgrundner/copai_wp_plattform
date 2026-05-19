# CoPAI Community-Plattform — Stack Design

**Status:** Draft for review
**Date:** 2026-05-19
**Author:** Peter Grundner (mit Claude Code)

## Ziel

Ein Docker-Compose-Stack, der die CoPAI-Community-Plattform vollständig orchestriert: WordPress (mit BuddyPress und zwei eigenen Plugins), Moodle als LMS, beides hinter einem Traefik-Reverse-Proxy mit Let's Encrypt. Konfiguration läuft über eine einzige `.env`-Datei im Projekt-Root.

Der Stack läuft ausschließlich im Traefik-Modus (kein lokaler Modus ohne Reverse-Proxy). Über einen Schalter in der `.env` kann zwischen Let's-Encrypt-Produktion und -Staging umgeschaltet werden, damit das Setup auch lokal oder hinter einer Firewall funktioniert.

## Nicht-Ziele (YAGNI)

- Kein SSO bzw. WP↔Moodle-Login-Sync. Zwei getrennte User-Bases.
- Kein Mailpit / MailHog. Externer SMTP-Server.
- Kein Backup-Skript (Datenstruktur ist nur backup-freundlich gestaltet).
- Kein Monitoring-/Logging-Stack.
- Keine staging/prod-Trennung in Compose. Ein Stack, Schalter über `TRAEFIK_TLS_RESOLVER`.
- Keine WP-Multisite.
- Kein Redis/Memcached.
- Kein automatisches WP-Plugin-Update.

## Architektur

### Services (9 Container)

| Service | Image / Build | Zweck |
|---|---|---|
| `traefik` | `traefik:v3` | Reverse-Proxy, ACME, Dashboard |
| `wp-nginx` | Build (`wordpress/Dockerfile.nginx`) | WordPress-Webserver |
| `wp-php` | Build (`wordpress/Dockerfile`) | PHP-FPM 8.2 + WordPress 6.9 + Plugins |
| `wp-cron` | Build (= wp-php) | minütlich `wp cron event run --due-now` |
| `wp-db` | `mariadb:10.11` | WordPress-DB |
| `moodle-nginx` | Build (`moodle/Dockerfile.nginx`) | Moodle-Webserver |
| `moodle-php` | Build (`moodle/Dockerfile`) | PHP-FPM 8.2 + Moodle |
| `moodle-cron` | Build (= moodle-php) | minütlich Moodle-Cron |
| `moodle-db` | `mariadb:10.11` + Moodle-Flags | Moodle-DB |

### Networks (3, klare Isolation)

- `proxy` — Traefik + `wp-nginx` + `moodle-nginx`. Sonst nichts. Wird extern angelegt (`docker network create proxy`).
- `wp-net` — `wp-nginx` ⇄ `wp-php` ⇄ `wp-cron` ⇄ `wp-db`. DBs sind nicht im `proxy`-Netz und damit nicht von außen erreichbar.
- `moodle-net` — analog für Moodle.

### Datenbank-Strategie

Zwei separate MariaDB-Container, weil Moodle MariaDB mit spezifischen Flags startet (`--transaction-isolation=READ-COMMITTED --binlog-format=ROW --skip-character-set-client-handshake --init-connect='SET NAMES utf8mb4'`), die WordPress nicht braucht. Vorteile: jede App optimale DB-Konfig, unabhängige Backups/Resets, ein DB-Crash legt nur eine App lahm.

## Verzeichnisstruktur

```
copai_wp_plattform/
├── .env                       # ALLE Konfig: Hostnames, Passwörter, Versionen, SMTP, ACME
├── env.example                # gleicher Inhalt, dummy-Werte, in Git
├── compose.yml                # ein einziges File (kein override)
├── README.md                  # auf Deutsch, analog moodle-docker
│
├── traefik/
│   ├── traefik.yml            # Static config (entrypoints, providers, certResolvers)
│   ├── dynamic/
│   │   └── dashboard.yml      # Dashboard-Router + Basic-Auth (envsubst beim Start)
│   └── acme.json              # LE-Zertifikate, chmod 600, gitignored
│
├── wordpress/
│   ├── Dockerfile             # FROM wordpress:6.9-fpm-php8.2 + Plugins build-time
│   ├── Dockerfile.nginx
│   ├── nginx.conf             # envsubst-Template
│   ├── docker-entrypoint.sh   # DB-Wait, wp core install bei leerer DB, Plugins aktivieren
│   └── mu-plugins/
│       └── smtp.php           # SMTP aus env-Vars (PHPMailer-Hook)
│
├── moodle/                    # aus bestehendem moodle-docker übernommen + angepasst
│   ├── Dockerfile
│   ├── Dockerfile.nginx
│   ├── nginx.conf
│   ├── docker-entrypoint.sh
│   └── config.php
│
└── data/                      # alle persistenten Daten in einem Ordner
    ├── wp/
    │   ├── content/           # wp-content (uploads, languages — Plugins liegen IM Image)
    │   └── db/                # wp-db Datenverzeichnis
    └── moodle/
        ├── moodledata/
        └── db/
```

Bewusste Entscheidungen:

- **Alle Daten in `data/`** statt verstreut — einfaches Backup (`tar czf backup.tar.gz data/`).
- **Eine einzige `compose.yml`** — kein Override, weil der lokale Modus nicht im Scope ist.
- **Plugins liegen im Image, nicht im Bind-Mount.** Im `wp-content`-Bind-Mount landen nur User-Daten (uploads, languages). Plugins sind Code und gehören ins Image.

## `.env` — Single Source of Truth

```bash
# === Global ===
COMPOSE_PROJECT_NAME=copai

# === Traefik ===
TRAEFIK_HOST=traefik.example.com
TRAEFIK_DASHBOARD_USER=admin
TRAEFIK_DASHBOARD_PASSWORD_HASH=$$apr1$$...   # htpasswd-Output, $-Zeichen verdoppeln
ACME_EMAIL=peter.grundner@gitprof.com
TRAEFIK_TLS_RESOLVER=letsencrypt-staging      # oder: letsencrypt
TRAEFIK_NETWORK=proxy

# === WordPress ===
WP_HOST=community.example.com
WP_VERSION=6.9
WP_PHP_VERSION=8.2
WP_TITLE=CoPAI Community
WP_ADMIN_USER=admin
WP_ADMIN_PASS=changeme
WP_ADMIN_EMAIL=peter.grundner@gitprof.com
WP_DB_NAME=wordpress
WP_DB_USER=wordpress
WP_DB_PASSWORD=changeme
WP_DB_ROOT_PASSWORD=changeme

# Plugin-Versionen (Git-Refs / WP-Repo-Versionen, build-time)
BUDDYPRESS_VERSION=14.0.0
JITSI_SPARRING_REF=main
MEETING_REGISTRATION_REF=main

# SMTP (externer Mailserver)
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM_EMAIL=no-reply@example.com
SMTP_FROM_NAME=CoPAI Community
SMTP_ENCRYPTION=tls                           # tls | ssl | none

# === Moodle ===
MOODLE_HOST=lms.example.com
MOODLE_GIT_BRANCH=MOODLE_404_STABLE
MOODLE_ADMIN_USER=admin
MOODLE_ADMIN_PASS=changeme
MOODLE_ADMIN_EMAIL=peter.grundner@gitprof.com
MOODLE_MAX_UPLOAD=512M
MOODLE_DB_NAME=moodle
MOODLE_DB_USER=moodle
MOODLE_DB_PASSWORD=changeme
MOODLE_DB_ROOT_PASSWORD=changeme
```

### Designentscheidungen zur `.env`

- **`TRAEFIK_TLS_RESOLVER` direkt statt `LE_STAGING`-Bool.** Compose kann keine Conditionals; eine Variable weniger und transparent. Werte: `letsencrypt-staging` oder `letsencrypt`.
- **`WP_URL` / `MOODLE_URL` werden NICHT in `.env` gehalten.** Werden aus `WP_HOST` / `MOODLE_HOST` zu `https://<host>` zusammengebaut. Hinter Traefik immer HTTPS.
- **`TRAEFIK_DASHBOARD_PASSWORD_HASH`-Escaping.** Jedes `$` als `$$`, weil Compose interpoliert. Wird im README dokumentiert.
- **Plugin-Versionen als Git-Refs.** Branch, Tag oder Commit. Rebuild zieht's.

## WordPress-Image — Build & Plugin-Installation

Das offizielle `wordpress:6.9-fpm-php8.2`-Image legt den Source unter `/usr/src/wordpress/` ab; der Entrypoint kopiert ihn beim Start nach `/var/www/html/`. Plugins müssen daher in `/usr/src/wordpress/wp-content/plugins/` installiert werden, sonst werden sie überschrieben.

### `wordpress/Dockerfile` (Skizze)

```dockerfile
ARG WP_VERSION=6.9
ARG WP_PHP_VERSION=8.2
FROM wordpress:${WP_VERSION}-fpm-php${WP_PHP_VERSION}

ARG BUDDYPRESS_VERSION
ARG JITSI_SPARRING_REF
ARG MEETING_REGISTRATION_REF

RUN apt-get update && apt-get install -y --no-install-recommends \
      git less mariadb-client unzip curl \
    && rm -rf /var/lib/apt/lists/*

# wp-cli
RUN curl -fsSL -o /usr/local/bin/wp \
      https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

WORKDIR /usr/src/wordpress/wp-content/plugins

# BuddyPress aus dem offiziellen WP-Plugin-Repo, gepinnt
RUN curl -fsSL "https://downloads.wordpress.org/plugin/buddypress.${BUDDYPRESS_VERSION}.zip" \
      -o /tmp/bp.zip \
    && unzip -q /tmp/bp.zip -d . && rm /tmp/bp.zip

# Eigene Plugins via git clone; .git wegwerfen
RUN git clone --depth=1 --branch="${JITSI_SPARRING_REF}" \
      https://github.com/pgrundner/copai-bp-jitsi-sparring.git \
    && rm -rf copai-bp-jitsi-sparring/.git \
 && git clone --depth=1 --branch="${MEETING_REGISTRATION_REF}" \
      https://github.com/pgrundner/copai-meeting-registration.git \
    && rm -rf copai-meeting-registration/.git

# MU-Plugin für SMTP — kein Admin-UI-Klick nötig
COPY mu-plugins/ /usr/src/wordpress/wp-content/mu-plugins/

# Eigener Entrypoint, der den offiziellen erweitert
COPY docker-entrypoint.sh /usr/local/bin/copai-entrypoint.sh
RUN chmod +x /usr/local/bin/copai-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/copai-entrypoint.sh"]
CMD ["php-fpm"]
```

### `wordpress/docker-entrypoint.sh` (Skizze)

```bash
#!/usr/bin/env bash
set -e

# 1) Offizieller WordPress-Entrypoint: kopiert /usr/src/wordpress nach /var/www/html
source /usr/local/bin/docker-entrypoint.sh

# 2) Auf DB warten
until mysqladmin ping -h"$WORDPRESS_DB_HOST" --silent; do sleep 2; done

# 3) Falls WP noch nicht installiert: wp core install + Plugins aktivieren
cd /var/www/html
if ! wp core is-installed --allow-root 2>/dev/null; then
  wp core install --allow-root \
    --url="https://${WP_HOST}" \
    --title="${WP_TITLE}" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASS}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
  wp plugin activate buddypress copai-bp-jitsi-sparring copai-meeting-registration --allow-root
fi

exec "$@"
```

### `wordpress/mu-plugins/smtp.php` (Skizze)

```php
<?php
add_action('phpmailer_init', function ($mail) {
    $mail->isSMTP();
    $mail->Host       = getenv('SMTP_HOST');
    $mail->Port       = (int) getenv('SMTP_PORT');
    $mail->SMTPAuth   = (bool) getenv('SMTP_USER');
    $mail->Username   = getenv('SMTP_USER');
    $mail->Password   = getenv('SMTP_PASS');
    $enc = getenv('SMTP_ENCRYPTION');
    if ($enc === 'tls' || $enc === 'ssl') {
        $mail->SMTPSecure = $enc;
    }
    $mail->From     = getenv('SMTP_FROM_EMAIL');
    $mail->FromName = getenv('SMTP_FROM_NAME');
});
```

### Anmerkungen

1. Plugin-/Core-Updates erfolgen über `.env`-Änderung + `docker compose build --no-cache wp-php wp-cron && docker compose up -d`. Image-Layer-Cache hält die Builds schnell.
2. Plugin-Aktivierung läuft nur bei der WP-Erstinstallation. Neue Plugins später → manuell aktivieren oder Entrypoint-Logik nachschärfen.
3. `mu-plugins` werden von WP automatisch geladen; nicht über das UI deaktivierbar — perfekt für „Pflicht“-Konfigurationen wie SMTP.

## Traefik — Static Config, ACME-Toggle, Dashboard

### `traefik/traefik.yml` (static)

```yaml
api:
  dashboard: true

entryPoints:
  web:
    address: ":80"
    http:
      redirections:
        entryPoint:
          to: websecure
          scheme: https
  websecure:
    address: ":443"

providers:
  docker:
    network: proxy
    exposedByDefault: false
  file:
    directory: /etc/traefik/dynamic
    watch: true

certificatesResolvers:
  letsencrypt:
    acme:
      email: ${ACME_EMAIL}
      storage: /acme/acme.json
      tlsChallenge: {}

  letsencrypt-staging:
    acme:
      email: ${ACME_EMAIL}
      storage: /acme/acme-staging.json
      caServer: https://acme-staging-v02.api.letsencrypt.org/directory
      tlsChallenge: {}
```

### `traefik/dynamic/dashboard.yml` (envsubst-Template)

```yaml
http:
  routers:
    dashboard:
      rule: "Host(`${TRAEFIK_HOST}`)"
      service: api@internal
      entryPoints: [websecure]
      tls:
        certResolver: ${TRAEFIK_TLS_RESOLVER}
      middlewares: [dashboard-auth]
  middlewares:
    dashboard-auth:
      basicAuth:
        users:
          - "${TRAEFIK_DASHBOARD_USER}:${TRAEFIK_DASHBOARD_PASSWORD_HASH}"
```

### Service-Definition (Auszug aus `compose.yml`)

```yaml
services:
  traefik:
    image: traefik:v3
    container_name: ${COMPOSE_PROJECT_NAME}-traefik
    entrypoint: ["/bin/sh", "-c"]
    command:
      - >-
        envsubst < /etc/traefik/traefik.yml.tmpl > /etc/traefik/traefik.yml &&
        envsubst < /etc/traefik/dynamic/dashboard.yml.tmpl > /etc/traefik/dynamic/dashboard.yml &&
        exec traefik --configFile=/etc/traefik/traefik.yml
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./traefik/traefik.yml:/etc/traefik/traefik.yml.tmpl:ro
      - ./traefik/dynamic/dashboard.yml:/etc/traefik/dynamic/dashboard.yml.tmpl:ro
      - ./traefik/acme.json:/acme/acme.json
    environment:
      - ACME_EMAIL
      - TRAEFIK_HOST
      - TRAEFIK_TLS_RESOLVER
      - TRAEFIK_DASHBOARD_USER
      - TRAEFIK_DASHBOARD_PASSWORD_HASH
    networks: [proxy]
    restart: unless-stopped
```

`envsubst` ist im offiziellen Traefik-Image nicht enthalten. Variante: `gettext`-Paket via Init-Container (gleiches Image-Layer) hinzufügen, oder ein schlankes Custom-Image `FROM traefik:v3` mit `apk add gettext`. Im Implementierungsplan konkretisieren.

### App-Service-Labels (gemeinsame Form)

```yaml
labels:
  - "traefik.enable=true"
  - "traefik.docker.network=${TRAEFIK_NETWORK}"
  - "traefik.http.services.wp.loadbalancer.server.port=80"
  - "traefik.http.routers.wp.rule=Host(`${WP_HOST}`)"
  - "traefik.http.routers.wp.entrypoints=websecure"
  - "traefik.http.routers.wp.tls.certresolver=${TRAEFIK_TLS_RESOLVER}"
```

## Moodle-Integration

Variante (c): die Files aus `moodle-docker` werden nach `./moodle/` kopiert und für den neuen Stack angepasst — kein Submodul, keine Git-Referenz auf das alte Repo.

### 1:1 übernommen

- `Dockerfile` (PHP-FPM 8.2 + Moodle-Clone aus Git)
- `Dockerfile.nginx`
- `nginx.conf`
- `docker-entrypoint.sh`
- `config.php`

### Anpassungen für den gemeinsamen Stack

| Bisher | Neu | Grund |
|---|---|---|
| Service `db` | Service `moodle-db` | Eindeutige Namen im gemeinsamen Stack |
| `MOODLE_DB_HOST=db` | `MOODLE_DB_HOST=moodle-db` | s.o. |
| `MOODLE_URL` als eigene `.env`-Var | aus `MOODLE_HOST` zu `https://${MOODLE_HOST}` gerendert | Konsistenz mit WP |
| `MOODLE_PORT`, `compose.local.yml` | weg | Nur Traefik-Modus |
| `env.example`-Drift (`MOODLE_HOST`, `TRAEFIK_ENTRYPOINT` u.a. fehlten) | in neuer `env.example` vollständig | Bekannter Bug im alten Repo |
| Reset-Doku: nur `moodledata/` | beide Pfade nennen | Bekannter Bug im alten Repo |

### Bind-Mount-Pfade

- `./moodle/config.php` → `/var/www/html/config.php`
- `./data/moodle/moodledata` → `/var/www/moodledata`
- `./data/moodle/db` → `/var/lib/mysql` (im `moodle-db`-Service)

### Was bleibt identisch

- `MOODLE_GIT_BRANCH`-Logik (Branch in `.env`, Rebuild zieht's)
- Moodle-spezifische MariaDB-Flags
- Auto-Install via `admin/cli/install_database.php` im Entrypoint
- `nginx.conf` als envsubst-Template
- Cron-Container mit `while true; do php admin/cli/cron.php; sleep 60; done`
- `chown moodledata` am Ende des Entrypoints (gegen Permission-Race)

## Initialer Setup-Ablauf

```bash
# 1) Proxy-Netzwerk anlegen (wird von compose als external referenziert)
docker network create proxy

# 2) Konfig
cp env.example .env
# .env editieren: Hostnames, Passwörter, ACME_EMAIL, SMTP-Daten
#  - Dashboard-Passwort-Hash erzeugen:
#    htpasswd -nbB admin "<deinPasswort>" | sed -e 's/\$/\$\$/g'

# 3) Leere acme.json mit korrekten Permissions anlegen
touch traefik/acme.json && chmod 600 traefik/acme.json

# 4) Daten-Verzeichnisse anlegen
mkdir -p data/wp/content data/wp/db data/moodle/moodledata data/moodle/db

# 5) Build + Start (erster Start dauert mehrere Minuten)
docker compose up -d --build

# 6) Logs verfolgen
docker compose logs -f wp-php moodle-php
```

Nach erfolgreichem Erststart:
- WordPress: `https://${WP_HOST}` — Admin-Zugang aus `.env`
- Moodle: `https://${MOODLE_HOST}` — Admin-Zugang aus `.env`
- Traefik-Dashboard: `https://${TRAEFIK_HOST}` — Basic-Auth aus `.env`

## Bekannte Stolperstellen (für README)

1. `htpasswd`-Hashes in `.env`: jedes `$` als `$$` (Compose-Interpolation).
2. Reset bedeutet **beide** Daten-Verzeichnisse löschen (Content + DB pro App), sonst überspringt der Entrypoint den Install.
3. `WP_VERSION` / `BUDDYPRESS_VERSION` / Plugin-Refs / `MOODLE_GIT_BRANCH` wirken erst nach `docker compose build --no-cache && up -d`.
4. Erststart dauert mehrere Minuten (Plugin-Downloads + Moodle-Git-Clone im Image).

## Offene Punkte für den Implementierungsplan

- Konkrete Form des envsubst-Schrittes für Traefik (Custom-Image vs. Init-Container vs. Inline-Command).
- Exaktes Verhalten des WP-Entrypoint-Wrappers (`source` vs. `exec` des offiziellen Entrypoints).
- `wp cron event run --due-now` als Loop im `wp-cron`-Container — minütlich, mit Logging-Strategie.
