# CoPAI Community-Plattform Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bootstrap a Docker-Compose stack at `/Users/pgrundner/Developer/Docker/copai_wp_plattform/` that runs WordPress (+ BuddyPress + two custom plugins), Moodle, and a Traefik reverse proxy with Let's Encrypt — all driven by a single `.env` file.

**Architecture:** Nine services across three Docker networks (`proxy`, `wp-net`, `moodle-net`). WordPress and Moodle each run as PHP-FPM + nginx + MariaDB + cron (4 containers each). Traefik sits in front of both. Configuration is centralised in `.env`; image builds bake in pinned versions of WordPress, BuddyPress, and the custom plugins. Let's-Encrypt staging vs. production is selectable through `TRAEFIK_TLS_RESOLVER`.

**Tech Stack:** Docker, Docker Compose v2, Traefik v3, WordPress 6.9 (`wordpress:fpm-php8.2`), BuddyPress, Moodle (Git-checkout in image), MariaDB 10.11, nginx (alpine), wp-cli, envsubst (`gettext`).

**Spec reference:** `docs/superpowers/specs/2026-05-19-copai-wp-moodle-stack-design.md`

---

## File Inventory

Files this plan creates, grouped by responsibility:

**Project root**
- `.gitignore` — excludes `.env`, `data/`, `traefik/acme*.json`
- `env.example` — every config variable with safe defaults
- `compose.yml` — all 9 services
- `README.md` — German setup docs

**Traefik (`traefik/`)**
- `Dockerfile` — adds `gettext` (for `envsubst`) onto `traefik:v3`
- `entrypoint.sh` — renders templates, execs traefik
- `traefik.yml.tmpl` — static config (entryPoints, providers, certResolvers)
- `dynamic/dashboard.yml.tmpl` — dashboard router + basic-auth middleware
- `acme.json`, `acme-staging.json` — empty files, chmod 600 (gitignored)

**WordPress (`wordpress/`)**
- `Dockerfile` — extends `wordpress:6.9-fpm-php8.2`, installs BuddyPress + the two custom plugins
- `Dockerfile.nginx` — minimal nginx:alpine + our config
- `nginx.conf` — envsubst template for FPM upstream + PHP routing
- `docker-entrypoint.sh` — wraps the upstream entrypoint, waits for DB, runs `wp core install`, activates plugins, syncs managed plugins on every start
- `mu-plugins/smtp.php` — SMTP config from env vars (PHPMailer hook)

**Moodle (`moodle/`)** — adapted from `../moodle-docker/`
- `Dockerfile`, `Dockerfile.nginx`, `nginx.conf`, `docker-entrypoint.sh`, `config.php`

**Data (gitignored, created by setup)**
- `data/wp/content/`, `data/wp/db/`
- `data/moodle/moodledata/`, `data/moodle/db/`

---

## Operational Notes for the Engineer

**The working directory `/Users/pgrundner/Developer/Docker/copai_wp_plattform/` is empty except for `docs/superpowers/specs/...` and `docs/superpowers/plans/...`. There is no git history yet — Task 1 initialises it.**

**Testing strategy for this project.** This is infrastructure code, not a library. There is no unit test framework. Each task ends with a concrete verification command (build, `docker compose config`, `curl`, `docker exec`) — these are the tests. Treat them as required: if a verification fails, fix the implementation before committing.

**Local-DNS prerequisite.** Many verifications need hostnames to resolve to `127.0.0.1`. Before running, add to `/etc/hosts`:
```
127.0.0.1 community.localtest.me lms.localtest.me traefik.localtest.me
```
(`localtest.me` already resolves wildcard to 127.0.0.1; the explicit `/etc/hosts` line just avoids surprises with caching resolvers.)

**curl with `-k`.** Let's-Encrypt staging certs are signed by an untrusted CA. All `curl https://...` checks in this plan use `-k`.

**Image build times.** First build clones Moodle from Git (slow, several minutes) and downloads BuddyPress + the two plugin repos. Plan accordingly.

---

## Task 1: Project skeleton, git init, gitignore

**Files:**
- Create: `/Users/pgrundner/Developer/Docker/copai_wp_plattform/.gitignore`

- [ ] **Step 1: Verify working directory is clean**

Run from `/Users/pgrundner/Developer/Docker/copai_wp_plattform`:
```bash
ls -la
```
Expected: only `docs/` is present (besides `.` and `..`).

- [ ] **Step 2: Initialise git**

```bash
git init
git branch -m main
```
Expected: `Initialized empty Git repository...`

- [ ] **Step 3: Create `.gitignore`**

Write `.gitignore`:
```gitignore
# Local env + secrets
.env

# Runtime data (bind-mounts)
data/

# Traefik ACME storage (created by setup, contains private keys)
traefik/acme.json
traefik/acme-staging.json

# OS noise
.DS_Store
```

- [ ] **Step 4: Stage and commit**

```bash
git add .gitignore docs/
git commit -m "chore: initial commit with brainstorming spec and plan"
```
Expected: commit created, `git log` shows one commit.

---

## Task 2: `env.example` — the canonical configuration surface

**Files:**
- Create: `env.example`

- [ ] **Step 1: Write `env.example`**

```dotenv
# === Global =================================================================
COMPOSE_PROJECT_NAME=copai

# === Traefik ================================================================
# Hostname the dashboard listens on
TRAEFIK_HOST=traefik.localtest.me
# Basic-Auth for the dashboard. Generate the hash with:
#   htpasswd -nbB admin "<your-password>" | sed -e 's/\$/\$\$/g'
# Each "$" in the hash MUST be doubled to survive compose interpolation.
TRAEFIK_DASHBOARD_USER=admin
TRAEFIK_DASHBOARD_PASSWORD_HASH=$$apr1$$REPLACE$$ME

ACME_EMAIL=peter.grundner@gitprof.com

# Which resolver to use. Allowed values:
#   letsencrypt           — production CA (rate-limited; only for real domains)
#   letsencrypt-staging   — staging CA (untrusted certs; use locally / behind firewalls)
TRAEFIK_TLS_RESOLVER=letsencrypt-staging

# Network name Traefik shares with app frontends
TRAEFIK_NETWORK=proxy

# === WordPress =============================================================
WP_HOST=community.localtest.me
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

# Plugin versions (rebuild needed to apply)
BUDDYPRESS_VERSION=14.0.0
JITSI_SPARRING_REF=main
MEETING_REGISTRATION_REF=main

# === SMTP (external mail server) ===========================================
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=
SMTP_PASS=
SMTP_FROM_EMAIL=no-reply@example.com
SMTP_FROM_NAME=CoPAI Community
# tls | ssl | none
SMTP_ENCRYPTION=tls

# === Moodle ================================================================
MOODLE_HOST=lms.localtest.me
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

- [ ] **Step 2: Copy to `.env` for the engineer's use**

```bash
cp env.example .env
```
Expected: `.env` exists, is gitignored.

- [ ] **Step 3: Verify gitignore**

```bash
git status --porcelain
```
Expected: shows `env.example` as new, **does NOT** list `.env`.

- [ ] **Step 4: Commit**

```bash
git add env.example
git commit -m "feat(config): add env.example with all configuration variables"
```

---

## Task 3: Traefik Dockerfile (adds `envsubst`) and entrypoint

**Files:**
- Create: `traefik/Dockerfile`
- Create: `traefik/entrypoint.sh`

- [ ] **Step 1: Write `traefik/Dockerfile`**

```dockerfile
FROM traefik:v3

# envsubst lives in the gettext package on alpine
RUN apk add --no-cache gettext

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
```

- [ ] **Step 2: Write `traefik/entrypoint.sh`**

```bash
#!/bin/sh
set -e

# Render every *.tmpl in /etc/traefik to its *.tmpl-less name
find /etc/traefik -name '*.tmpl' | while read -r tmpl; do
    out="${tmpl%.tmpl}"
    envsubst < "$tmpl" > "$out"
done

exec traefik "$@"
```

- [ ] **Step 3: Verify the image builds in isolation**

```bash
docker build -t copai-traefik-test ./traefik
```
Expected: successful build, image tagged.

- [ ] **Step 4: Verify envsubst works inside the image**

```bash
echo 'hello $NAME' | docker run --rm -i -e NAME=world copai-traefik-test sh -c 'envsubst'
```
Expected output:
```
hello world
```

- [ ] **Step 5: Clean up test image**

```bash
docker rmi copai-traefik-test
```

- [ ] **Step 6: Commit**

```bash
git add traefik/Dockerfile traefik/entrypoint.sh
git commit -m "feat(traefik): custom image with envsubst for template rendering"
```

---

## Task 4: Traefik static + dynamic config templates

**Files:**
- Create: `traefik/traefik.yml.tmpl`
- Create: `traefik/dynamic/dashboard.yml.tmpl`

- [ ] **Step 1: Write `traefik/traefik.yml.tmpl`**

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

log:
  level: INFO

accessLog: {}
```

- [ ] **Step 2: Write `traefik/dynamic/dashboard.yml.tmpl`**

```yaml
http:
  routers:
    dashboard:
      rule: "Host(`${TRAEFIK_HOST}`)"
      service: api@internal
      entryPoints:
        - websecure
      tls:
        certResolver: ${TRAEFIK_TLS_RESOLVER}
      middlewares:
        - dashboard-auth

  middlewares:
    dashboard-auth:
      basicAuth:
        users:
          - "${TRAEFIK_DASHBOARD_USER}:${TRAEFIK_DASHBOARD_PASSWORD_HASH}"
```

- [ ] **Step 3: Create ACME storage stubs**

```bash
touch traefik/acme.json traefik/acme-staging.json
chmod 600 traefik/acme.json traefik/acme-staging.json
```

- [ ] **Step 4: Verify gitignore**

```bash
git status --porcelain
```
Expected: `traefik/Dockerfile`, `traefik/entrypoint.sh`, `traefik/traefik.yml.tmpl`, `traefik/dynamic/dashboard.yml.tmpl` listed; `acme.json` / `acme-staging.json` NOT listed.

- [ ] **Step 5: Commit**

```bash
git add traefik/traefik.yml.tmpl traefik/dynamic/dashboard.yml.tmpl
git commit -m "feat(traefik): static + dynamic config templates"
```

---

## Task 5: First `compose.yml` — Traefik service only, end-to-end smoke

**Files:**
- Create: `compose.yml`

The goal of this task is to get the *isolated* Traefik service starting cleanly and serving its dashboard with HTTPS+basic-auth. WordPress and Moodle come later.

- [ ] **Step 1: Write minimal `compose.yml`**

```yaml
name: ${COMPOSE_PROJECT_NAME}

services:
  traefik:
    build:
      context: ./traefik
    container_name: ${COMPOSE_PROJECT_NAME}-traefik
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./traefik/traefik.yml.tmpl:/etc/traefik/traefik.yml.tmpl:ro
      - ./traefik/dynamic:/etc/traefik/dynamic:ro
      - ./traefik/acme.json:/acme/acme.json
      - ./traefik/acme-staging.json:/acme/acme-staging.json
      - /var/run/docker.sock:/var/run/docker.sock:ro
    environment:
      - ACME_EMAIL
      - TRAEFIK_HOST
      - TRAEFIK_TLS_RESOLVER
      - TRAEFIK_DASHBOARD_USER
      - TRAEFIK_DASHBOARD_PASSWORD_HASH
    networks:
      - proxy

networks:
  proxy:
    name: ${TRAEFIK_NETWORK}
    external: true
```

- [ ] **Step 2: Prepare dashboard credentials**

The default `env.example` has `REPLACE$$ME` as the password hash, which is invalid. Generate a real hash:
```bash
htpasswd -nbB admin "test1234" | sed -e 's/\$/\$\$/g'
```
Expected: output like `admin:$$2y$$05$$...` — copy this into `.env` as `TRAEFIK_DASHBOARD_PASSWORD_HASH=$$2y$$05$$...` (the literal `admin:` prefix is NOT included; only the hash part after the colon).

(Note: the actual `.env` file is the engineer's local file, not committed.)

- [ ] **Step 3: Validate compose syntax**

```bash
docker compose config > /dev/null
```
Expected: no errors. Warnings about the missing `proxy` network are fine.

- [ ] **Step 4: Create the external proxy network**

```bash
docker network create proxy
```
Expected: hash output, or `network with name proxy already exists` — either is fine.

- [ ] **Step 5: Build and start**

```bash
docker compose up -d --build
```
Expected: traefik container starts and stays running. Verify:
```bash
docker compose ps
```

- [ ] **Step 6: Smoke-test the dashboard router**

```bash
# 80 → 443 redirect
curl -ksI http://traefik.localtest.me | head -5
# Expected: HTTP/1.1 308 Permanent Redirect, Location: https://...

# 443 with no auth → 401
curl -ksI https://traefik.localtest.me | head -5
# Expected: HTTP/2 401, www-authenticate: Basic realm=...

# 443 with auth → 200
curl -ksI -u admin:test1234 https://traefik.localtest.me | head -5
# Expected: HTTP/2 200
```

- [ ] **Step 7: Tail logs for any errors**

```bash
docker compose logs traefik | grep -iE 'error|warn' | head -20
```
Expected: no fatal errors. ACME warnings about staging CA / DNS are expected when the host is not publicly reachable.

- [ ] **Step 8: Tear down**

```bash
docker compose down
```

- [ ] **Step 9: Commit**

```bash
git add compose.yml
git commit -m "feat(compose): bootstrap with Traefik service only"
```

---

## Task 6: WordPress MariaDB service

**Files:**
- Modify: `compose.yml`

- [ ] **Step 1: Add the `wp-db` service to `compose.yml`**

Add under `services:` (after `traefik:`):
```yaml
  wp-db:
    image: mariadb:10.11
    container_name: ${COMPOSE_PROJECT_NAME}-wp-db
    restart: unless-stopped
    command: >-
      --character-set-server=utf8mb4
      --collation-server=utf8mb4_unicode_ci
    environment:
      - MARIADB_ROOT_PASSWORD=${WP_DB_ROOT_PASSWORD}
      - MARIADB_DATABASE=${WP_DB_NAME}
      - MARIADB_USER=${WP_DB_USER}
      - MARIADB_PASSWORD=${WP_DB_PASSWORD}
    volumes:
      - ./data/wp/db:/var/lib/mysql
    networks:
      - wp-net
```

Add `wp-net` under `networks:` (after the `proxy:` entry):
```yaml
  wp-net:
    name: ${COMPOSE_PROJECT_NAME}-wp-net
```

- [ ] **Step 2: Create the data directory**

```bash
mkdir -p data/wp/db
```

- [ ] **Step 3: Validate**

```bash
docker compose config > /dev/null
```
Expected: no errors.

- [ ] **Step 4: Start wp-db in isolation**

```bash
docker compose up -d wp-db
```
Expected: container starts. Verify it accepts connections:
```bash
docker compose exec wp-db mariadb-admin ping -uroot -p"$(grep WP_DB_ROOT_PASSWORD .env | cut -d= -f2)"
```
Expected: `mariadbd is alive`.

- [ ] **Step 5: Verify the DB and user exist**

```bash
docker compose exec wp-db mariadb -uroot -p"$(grep WP_DB_ROOT_PASSWORD .env | cut -d= -f2)" -e "SHOW DATABASES; SELECT User, Host FROM mysql.user;"
```
Expected: `wordpress` DB listed; `wordpress` user present.

- [ ] **Step 6: Tear down**

```bash
docker compose down
```

- [ ] **Step 7: Commit**

```bash
git add compose.yml
git commit -m "feat(compose): add wp-db service"
```

---

## Task 7: WordPress mu-plugin for SMTP

**Files:**
- Create: `wordpress/mu-plugins/smtp.php`

- [ ] **Step 1: Write `wordpress/mu-plugins/smtp.php`**

```php
<?php
/**
 * Plugin Name: CoPAI SMTP (mu)
 * Description: Forces SMTP delivery using settings from environment variables.
 */

add_action('phpmailer_init', function ($mail) {
    $host = getenv('SMTP_HOST');
    if (!$host) {
        return;
    }

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);

    $user = getenv('SMTP_USER');
    $pass = getenv('SMTP_PASS');
    if ($user !== false && $user !== '') {
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
    } else {
        $mail->SMTPAuth = false;
    }

    $enc = strtolower((string) getenv('SMTP_ENCRYPTION'));
    if ($enc === 'tls' || $enc === 'ssl') {
        $mail->SMTPSecure = $enc;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $from = getenv('SMTP_FROM_EMAIL');
    $name = getenv('SMTP_FROM_NAME');
    if ($from) {
        $mail->From = $from;
    }
    if ($name) {
        $mail->FromName = $name;
    }
});

// Make sure wp_mail's "From:" doesn't override our SMTP_FROM_EMAIL.
add_filter('wp_mail_from', function ($email) {
    $from = getenv('SMTP_FROM_EMAIL');
    return $from ?: $email;
}, 100);

add_filter('wp_mail_from_name', function ($name) {
    $from = getenv('SMTP_FROM_NAME');
    return $from ?: $name;
}, 100);
```

- [ ] **Step 2: PHP syntax check**

```bash
docker run --rm -v "$(pwd)/wordpress/mu-plugins:/work" php:8.2-cli php -l /work/smtp.php
```
Expected: `No syntax errors detected in /work/smtp.php`.

- [ ] **Step 3: Commit**

```bash
git add wordpress/mu-plugins/smtp.php
git commit -m "feat(wp): mu-plugin forcing SMTP from env vars"
```

---

## Task 8: WordPress PHP-FPM Dockerfile with build-time plugins

**Files:**
- Create: `wordpress/Dockerfile`
- Create: `wordpress/docker-entrypoint.sh`

- [ ] **Step 1: Write `wordpress/Dockerfile`**

```dockerfile
ARG WP_VERSION=6.9
ARG WP_PHP_VERSION=8.2

FROM wordpress:${WP_VERSION}-fpm-php${WP_PHP_VERSION}

ARG BUDDYPRESS_VERSION
ARG JITSI_SPARRING_REF
ARG MEETING_REGISTRATION_REF

RUN apt-get update && apt-get install -y --no-install-recommends \
        git less mariadb-client unzip curl \
        ca-certificates rsync \
    && rm -rf /var/lib/apt/lists/*

# wp-cli
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp

# Install BuddyPress + the two custom plugins INTO the image source dir.
# /usr/src/wordpress is what the upstream entrypoint copies into /var/www/html.
WORKDIR /usr/src/wordpress/wp-content/plugins

RUN curl -fsSL "https://downloads.wordpress.org/plugin/buddypress.${BUDDYPRESS_VERSION}.zip" \
        -o /tmp/bp.zip \
    && unzip -q /tmp/bp.zip -d . \
    && rm /tmp/bp.zip

RUN git clone --depth=1 --branch="${JITSI_SPARRING_REF}" \
        https://github.com/pgrundner/copai-bp-jitsi-sparring.git \
    && rm -rf copai-bp-jitsi-sparring/.git

RUN git clone --depth=1 --branch="${MEETING_REGISTRATION_REF}" \
        https://github.com/pgrundner/copai-meeting-registration.git \
    && rm -rf copai-meeting-registration/.git

# mu-plugins (SMTP)
COPY mu-plugins/ /usr/src/wordpress/wp-content/mu-plugins/

# Custom entrypoint
COPY docker-entrypoint.sh /usr/local/bin/copai-entrypoint.sh
RUN chmod +x /usr/local/bin/copai-entrypoint.sh
ENTRYPOINT ["/usr/local/bin/copai-entrypoint.sh"]
CMD ["php-fpm"]
```

- [ ] **Step 2: Write `wordpress/docker-entrypoint.sh`**

This wraps the upstream WP entrypoint, then waits for the DB, then installs WP if needed, then syncs our managed plugins+mu-plugins from `/usr/src/wordpress/` into the live tree on **every** start so plugin rebuilds propagate.

```bash
#!/usr/bin/env bash
set -e

# 1) Upstream entrypoint copies /usr/src/wordpress → /var/www/html
#    if /var/www/html is empty. We source it so it runs to completion
#    but don't let it exec php-fpm yet — we exec at the end.
#    The image's entrypoint is at /usr/local/bin/docker-entrypoint.sh.
if [ -f /usr/local/bin/docker-entrypoint.sh ]; then
    # Run it with a no-op command so it does its file-copy work without execing.
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
    # Wait for DB
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
```

- [ ] **Step 3: Build the image standalone, to surface plugin-fetch errors early**

```bash
docker build \
    --build-arg WP_VERSION=6.9 \
    --build-arg WP_PHP_VERSION=8.2 \
    --build-arg BUDDYPRESS_VERSION=14.0.0 \
    --build-arg JITSI_SPARRING_REF=main \
    --build-arg MEETING_REGISTRATION_REF=main \
    -t copai-wp-test ./wordpress
```
Expected: successful build; all three plugins clone without error.

- [ ] **Step 4: Verify plugins landed in the image**

```bash
docker run --rm copai-wp-test ls /usr/src/wordpress/wp-content/plugins
```
Expected output includes:
```
akismet
buddypress
copai-bp-jitsi-sparring
copai-meeting-registration
hello.php
```

- [ ] **Step 5: Verify mu-plugin is in the image**

```bash
docker run --rm copai-wp-test ls /usr/src/wordpress/wp-content/mu-plugins
```
Expected: `smtp.php`.

- [ ] **Step 6: Clean up test image**

```bash
docker rmi copai-wp-test
```

- [ ] **Step 7: Commit**

```bash
git add wordpress/Dockerfile wordpress/docker-entrypoint.sh
git commit -m "feat(wp): image with build-time plugins and install entrypoint"
```

---

## Task 9: WordPress nginx image + config

**Files:**
- Create: `wordpress/Dockerfile.nginx`
- Create: `wordpress/nginx.conf`

The nginx container needs the WordPress source files to serve static assets directly (`/wp-content/uploads/...`, `/wp-includes/js/...`). We share the live tree via the same bind-mount that wp-php uses (more on this in the compose task).

- [ ] **Step 1: Write `wordpress/Dockerfile.nginx`**

```dockerfile
FROM nginx:alpine

# envsubst lives here in alpine
RUN apk add --no-cache gettext

# Our template lands in /etc/nginx/templates and the official entrypoint
# auto-renders any *.template file there.
COPY nginx.conf /etc/nginx/templates/default.conf.template
```

- [ ] **Step 2: Write `wordpress/nginx.conf`**

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /var/www/html;
    index index.php;

    client_max_body_size ${WP_MAX_UPLOAD};

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        fastcgi_pass wp-php:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        fastcgi_read_timeout 300;
    }

    # Long cache for static assets
    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff2?)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    # Block dotfiles
    location ~ /\. {
        deny all;
    }
}
```

- [ ] **Step 3: Add `WP_MAX_UPLOAD` to `env.example`**

In `env.example`, in the WordPress section, after `WP_PHP_VERSION`, add:
```dotenv
WP_MAX_UPLOAD=64M
```
And mirror it into your local `.env`.

- [ ] **Step 4: Validate the Dockerfile builds**

```bash
docker build -t copai-wp-nginx-test -f ./wordpress/Dockerfile.nginx ./wordpress
```
Expected: build succeeds.

- [ ] **Step 5: Clean up**

```bash
docker rmi copai-wp-nginx-test
```

- [ ] **Step 6: Commit**

```bash
git add wordpress/Dockerfile.nginx wordpress/nginx.conf env.example
git commit -m "feat(wp): nginx image + envsubst-templated config"
```

---

## Task 10: Add WordPress services to `compose.yml`

**Files:**
- Modify: `compose.yml`

- [ ] **Step 1: Add WP services**

Add under `services:` (after `wp-db:`):

```yaml
  wp-php:
    build:
      context: ./wordpress
      args:
        - WP_VERSION=${WP_VERSION}
        - WP_PHP_VERSION=${WP_PHP_VERSION}
        - BUDDYPRESS_VERSION=${BUDDYPRESS_VERSION}
        - JITSI_SPARRING_REF=${JITSI_SPARRING_REF}
        - MEETING_REGISTRATION_REF=${MEETING_REGISTRATION_REF}
    container_name: ${COMPOSE_PROJECT_NAME}-wp-php
    restart: unless-stopped
    depends_on:
      - wp-db
    environment:
      - WORDPRESS_DB_HOST=wp-db
      - WORDPRESS_DB_NAME=${WP_DB_NAME}
      - WORDPRESS_DB_USER=${WP_DB_USER}
      - WORDPRESS_DB_PASSWORD=${WP_DB_PASSWORD}
      - WP_HOST
      - WP_TITLE
      - WP_ADMIN_USER
      - WP_ADMIN_PASS
      - WP_ADMIN_EMAIL
      - SMTP_HOST
      - SMTP_PORT
      - SMTP_USER
      - SMTP_PASS
      - SMTP_FROM_EMAIL
      - SMTP_FROM_NAME
      - SMTP_ENCRYPTION
      - COPAI_RUN_INSTALL=1
    volumes:
      - wp-html:/var/www/html
      - ./data/wp/content:/var/www/html/wp-content
    networks:
      - wp-net

  wp-nginx:
    build:
      context: ./wordpress
      dockerfile: Dockerfile.nginx
    container_name: ${COMPOSE_PROJECT_NAME}-wp-nginx
    restart: unless-stopped
    depends_on:
      - wp-php
    environment:
      - WP_MAX_UPLOAD
    volumes:
      - wp-html:/var/www/html:ro
      - ./data/wp/content:/var/www/html/wp-content:ro
    networks:
      - wp-net
      - proxy
    labels:
      - "traefik.enable=true"
      - "traefik.docker.network=${TRAEFIK_NETWORK}"
      - "traefik.http.services.wp.loadbalancer.server.port=80"
      - "traefik.http.routers.wp.rule=Host(`${WP_HOST}`)"
      - "traefik.http.routers.wp.entrypoints=websecure"
      - "traefik.http.routers.wp.tls.certresolver=${TRAEFIK_TLS_RESOLVER}"

  wp-cron:
    build:
      context: ./wordpress
      args:
        - WP_VERSION=${WP_VERSION}
        - WP_PHP_VERSION=${WP_PHP_VERSION}
        - BUDDYPRESS_VERSION=${BUDDYPRESS_VERSION}
        - JITSI_SPARRING_REF=${JITSI_SPARRING_REF}
        - MEETING_REGISTRATION_REF=${MEETING_REGISTRATION_REF}
    container_name: ${COMPOSE_PROJECT_NAME}-wp-cron
    restart: unless-stopped
    depends_on:
      - wp-php
    environment:
      - WORDPRESS_DB_HOST=wp-db
      - WORDPRESS_DB_NAME=${WP_DB_NAME}
      - WORDPRESS_DB_USER=${WP_DB_USER}
      - WORDPRESS_DB_PASSWORD=${WP_DB_PASSWORD}
    volumes:
      - wp-html:/var/www/html
      - ./data/wp/content:/var/www/html/wp-content
    networks:
      - wp-net
    entrypoint: ["/bin/bash", "-c"]
    command:
      - >-
        until wp --allow-root --path=/var/www/html core is-installed 2>/dev/null;
        do sleep 5; done;
        while true; do
          wp --allow-root --path=/var/www/html cron event run --due-now;
          sleep 60;
        done
```

- [ ] **Step 2: Add the named volume**

At the bottom of `compose.yml`, add a `volumes:` block (or extend an existing one):
```yaml
volumes:
  wp-html:
    name: ${COMPOSE_PROJECT_NAME}-wp-html
```

- [ ] **Step 3: Create the bind-mount directories**

```bash
mkdir -p data/wp/content
```
(The `/var/www/html/wp-content` bind-mount will be populated by the entrypoint's plugin sync on first start.)

- [ ] **Step 4: Validate**

```bash
docker compose config > /dev/null
```
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add compose.yml
git commit -m "feat(compose): add wp-php, wp-nginx, wp-cron services"
```

---

## Task 11: End-to-end WordPress smoke test

This is the largest verification step. If anything is wrong with the WP setup (plugin sync, install, routing), it surfaces here.

- [ ] **Step 1: Fresh start, full build**

```bash
docker compose up -d --build
```
Expected: traefik, wp-db, wp-php, wp-nginx, wp-cron all running. First build takes minutes.

- [ ] **Step 2: Wait for WP install**

Watch the wp-php logs until you see the install line:
```bash
docker compose logs -f wp-php
```
Expected: lines like
```
Waiting for wp-db...
Installing WordPress...
Success: WordPress installed successfully.
Plugin 'buddypress' activated.
Plugin 'copai-bp-jitsi-sparring' activated.
Plugin 'copai-meeting-registration' activated.
```
Then Ctrl-C.

- [ ] **Step 3: HTTP-level smoke test**

```bash
curl -ksI https://community.localtest.me | head -5
```
Expected: `HTTP/2 200` (or `HTTP/2 302` redirect to install page if step 2 hasn't completed yet — re-run after a few seconds).

- [ ] **Step 4: Verify plugins are active**

```bash
docker compose exec -u www-data wp-php wp plugin list --status=active
```
Expected: a table listing `buddypress`, `copai-bp-jitsi-sparring`, `copai-meeting-registration` as `active`.

- [ ] **Step 5: Verify wp-content bind-mount is populated**

```bash
ls data/wp/content/plugins
```
Expected: `buddypress`, `copai-bp-jitsi-sparring`, `copai-meeting-registration`, `hello.php` (and possibly `akismet`, `index.php`).

```bash
ls data/wp/content/mu-plugins
```
Expected: `smtp.php`.

- [ ] **Step 6: Verify the SMTP mu-plugin is loaded**

```bash
docker compose exec -u www-data wp-php wp eval 'echo function_exists("wp_mail") ? "ok" : "fail";'
```
Expected: `ok`.

(A full SMTP send-test requires a reachable SMTP server and is left for the engineer's environment.)

- [ ] **Step 7: Verify wp-cron is looping**

```bash
docker compose logs --tail=20 wp-cron
```
Expected: lines showing repeated cron runs every ~60 seconds (or "No scheduled events" lines).

- [ ] **Step 8: Tear down and reset for next task**

```bash
docker compose down
```
(Leave `data/` intact — Task 13 will piggy-back on this WP install.)

- [ ] **Step 9: Commit (no code changes, but record verification)**

If any small fixes were needed during this task, commit them now:
```bash
git status
git add <fixed-files-if-any>
git commit -m "fix(wp): adjustments from end-to-end smoke test"
```
If no fixes are needed, skip the commit.

---

## Task 12: Copy + adapt Moodle files from `../moodle-docker/`

**Files (all created under `moodle/`):**
- Create: `moodle/Dockerfile`
- Create: `moodle/Dockerfile.nginx`
- Create: `moodle/nginx.conf`
- Create: `moodle/docker-entrypoint.sh`
- Create: `moodle/config.php`

Source repo: `/Users/pgrundner/Developer/Docker/moodle-docker/`.

- [ ] **Step 1: Copy the files**

```bash
mkdir -p moodle
cp ../moodle-docker/Dockerfile moodle/Dockerfile
cp ../moodle-docker/Dockerfile.nginx moodle/Dockerfile.nginx
cp ../moodle-docker/nginx.conf moodle/nginx.conf
cp ../moodle-docker/docker-entrypoint.sh moodle/docker-entrypoint.sh
cp ../moodle-docker/config.php moodle/config.php
chmod +x moodle/docker-entrypoint.sh
```

- [ ] **Step 2: Read what you just copied**

Read each file once to understand what the entrypoint does and what env vars the config expects. The expected vars include: `MOODLE_DB_HOST`, `MOODLE_DB_NAME`, `MOODLE_DB_USER`, `MOODLE_DB_PASSWORD`, `MOODLE_URL`, `MOODLE_ADMIN_USER`, `MOODLE_ADMIN_PASS`, `MOODLE_ADMIN_EMAIL`, `MOODLE_MAX_UPLOAD`.

- [ ] **Step 3: Adapt `moodle/config.php` if it references the old DB hostname**

If you see `'dbhost' => 'db'` or `getenv('MOODLE_DB_HOST') ?: 'db'`, change the fallback (or hardcoded value) to `moodle-db`. Prefer reading exclusively from env vars.

If everything in `config.php` is already env-driven (i.e. `getenv('MOODLE_DB_HOST')` with no fallback) — leave it alone; we'll set `MOODLE_DB_HOST=moodle-db` in compose.

- [ ] **Step 4: Adapt the entrypoint if it hardcodes the DB host**

Same check in `moodle/docker-entrypoint.sh`. Replace any hardcoded `db` with `$MOODLE_DB_HOST`.

- [ ] **Step 5: Commit**

```bash
git add moodle/
git commit -m "feat(moodle): import and adapt files from moodle-docker"
```

---

## Task 13: Add Moodle services to `compose.yml`

**Files:**
- Modify: `compose.yml`

- [ ] **Step 1: Add Moodle services**

Add under `services:`:

```yaml
  moodle-db:
    image: mariadb:10.11
    container_name: ${COMPOSE_PROJECT_NAME}-moodle-db
    restart: unless-stopped
    command: >-
      --transaction-isolation=READ-COMMITTED
      --binlog-format=ROW
      --skip-character-set-client-handshake
      --init-connect=SET NAMES utf8mb4
    environment:
      - MARIADB_ROOT_PASSWORD=${MOODLE_DB_ROOT_PASSWORD}
      - MARIADB_DATABASE=${MOODLE_DB_NAME}
      - MARIADB_USER=${MOODLE_DB_USER}
      - MARIADB_PASSWORD=${MOODLE_DB_PASSWORD}
    volumes:
      - ./data/moodle/db:/var/lib/mysql
    networks:
      - moodle-net

  moodle-php:
    build:
      context: ./moodle
      args:
        - MOODLE_GIT_BRANCH=${MOODLE_GIT_BRANCH}
    container_name: ${COMPOSE_PROJECT_NAME}-moodle-php
    restart: unless-stopped
    depends_on:
      - moodle-db
    environment:
      - MOODLE_DB_HOST=moodle-db
      - MOODLE_DB_NAME=${MOODLE_DB_NAME}
      - MOODLE_DB_USER=${MOODLE_DB_USER}
      - MOODLE_DB_PASSWORD=${MOODLE_DB_PASSWORD}
      - MOODLE_URL=https://${MOODLE_HOST}
      - MOODLE_ADMIN_USER=${MOODLE_ADMIN_USER}
      - MOODLE_ADMIN_PASS=${MOODLE_ADMIN_PASS}
      - MOODLE_ADMIN_EMAIL=${MOODLE_ADMIN_EMAIL}
      - MOODLE_MAX_UPLOAD=${MOODLE_MAX_UPLOAD}
    volumes:
      - ./moodle/config.php:/var/www/html/config.php
      - ./data/moodle/moodledata:/var/www/moodledata
    networks:
      - moodle-net

  moodle-nginx:
    build:
      context: ./moodle
      dockerfile: Dockerfile.nginx
      args:
        - MOODLE_GIT_BRANCH=${MOODLE_GIT_BRANCH}
    container_name: ${COMPOSE_PROJECT_NAME}-moodle-nginx
    restart: unless-stopped
    depends_on:
      - moodle-php
    environment:
      - MOODLE_MAX_UPLOAD=${MOODLE_MAX_UPLOAD}
    volumes:
      - ./data/moodle/moodledata:/var/www/moodledata
    networks:
      - moodle-net
      - proxy
    labels:
      - "traefik.enable=true"
      - "traefik.docker.network=${TRAEFIK_NETWORK}"
      - "traefik.http.services.moodle.loadbalancer.server.port=80"
      - "traefik.http.routers.moodle.rule=Host(`${MOODLE_HOST}`)"
      - "traefik.http.routers.moodle.entrypoints=websecure"
      - "traefik.http.routers.moodle.tls.certresolver=${TRAEFIK_TLS_RESOLVER}"

  moodle-cron:
    build:
      context: ./moodle
      args:
        - MOODLE_GIT_BRANCH=${MOODLE_GIT_BRANCH}
    container_name: ${COMPOSE_PROJECT_NAME}-moodle-cron
    restart: unless-stopped
    depends_on:
      - moodle-db
      - moodle-php
    entrypoint: []
    command:
      - bash
      - -c
      - >-
        echo 'Starting Moodle cron loop...';
        while true; do
          php /var/www/html/admin/cli/cron.php > /dev/null 2>&1;
          sleep 60;
        done
    environment:
      - MOODLE_DB_HOST=moodle-db
      - MOODLE_DB_NAME=${MOODLE_DB_NAME}
      - MOODLE_DB_USER=${MOODLE_DB_USER}
      - MOODLE_DB_PASSWORD=${MOODLE_DB_PASSWORD}
      - MOODLE_URL=https://${MOODLE_HOST}
    volumes:
      - ./moodle/config.php:/var/www/html/config.php
      - ./data/moodle/moodledata:/var/www/moodledata
    networks:
      - moodle-net
```

- [ ] **Step 2: Add `moodle-net` to the `networks:` block at the bottom**

```yaml
  moodle-net:
    name: ${COMPOSE_PROJECT_NAME}-moodle-net
```

- [ ] **Step 3: Create bind-mount directories**

```bash
mkdir -p data/moodle/db data/moodle/moodledata
```

- [ ] **Step 4: Validate**

```bash
docker compose config > /dev/null
```
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add compose.yml
git commit -m "feat(compose): add moodle-db, moodle-php, moodle-nginx, moodle-cron"
```

---

## Task 14: End-to-end Moodle smoke test

- [ ] **Step 1: Start the full stack**

```bash
docker compose up -d --build
```
Expected: all 9 services run. First-time Moodle build is **slow** (Git clone of Moodle in two images).

- [ ] **Step 2: Watch Moodle install**

```bash
docker compose logs -f moodle-php
```
Expected: lines about waiting for DB, then "Database tables setup", then login info. May take several minutes the first time.

- [ ] **Step 3: HTTP smoke test**

```bash
curl -ksI https://lms.localtest.me | head -5
```
Expected: `HTTP/2 200` or `HTTP/2 303` (Moodle redirects to login).

- [ ] **Step 4: Verify Moodle admin can log in**

```bash
docker compose exec moodle-php php /var/www/html/admin/cli/check_database_schema.php
```
Expected: empty output (no schema errors) or a brief status line.

- [ ] **Step 5: Verify cron is running**

```bash
docker compose logs --tail=10 moodle-cron
```
Expected: at minimum the "Starting Moodle cron loop..." line; possibly Moodle cron output lines.

- [ ] **Step 6: Verify network isolation**

```bash
docker compose exec wp-php sh -c 'ping -c1 -W1 moodle-db 2>&1 | head -2'
```
Expected: failure (`unknown host` or `Network unreachable`) — wp-php is on `wp-net`, not `moodle-net`.

```bash
docker compose exec wp-nginx sh -c 'wget -qO- --timeout=2 http://moodle-nginx 2>&1 | head -1'
```
Expected: connection succeeds (both nginx containers share `proxy`).

- [ ] **Step 7: Tear down**

```bash
docker compose down
```

- [ ] **Step 8: Commit any fixes**

If you adjusted anything (env vars, paths), commit now:
```bash
git status
git add <files>
git commit -m "fix(moodle): adjustments from end-to-end smoke test"
```

---

## Task 15: `README.md` — German setup docs

**Files:**
- Create: `README.md`

- [ ] **Step 1: Write `README.md`**

```markdown
# CoPAI Community-Plattform

Ein Docker-Compose-Stack mit WordPress (+ BuddyPress + zwei eigene Plugins), Moodle, und Traefik als Reverse-Proxy. Konfiguration läuft komplett über die zentrale `.env`-Datei im Root.

## Architektur

9 Container in 3 Netzwerken:

- **traefik** — Reverse-Proxy mit Let's Encrypt (Staging oder Produktion umschaltbar)
- **wp-php**, **wp-nginx**, **wp-cron**, **wp-db** — WordPress-Stack auf `wp-net`
- **moodle-php**, **moodle-nginx**, **moodle-cron**, **moodle-db** — Moodle-Stack auf `moodle-net`

Nur die beiden nginx-Container hängen zusätzlich am `proxy`-Netz und sind damit für Traefik sichtbar. Datenbanken sind von außen nicht erreichbar.

## Voraussetzungen

- Docker mit Compose-Plugin (`docker compose ...`)
- `htpasswd` (Paket `apache2-utils` auf Debian/Ubuntu, `httpd-tools` auf RHEL)
- Hostnamen müssen auf den Host auflösen — lokal über `/etc/hosts`, in Produktion über öffentliches DNS

## Erstes Setup

### 1. Konfig anlegen

```bash
cp env.example .env
```

`.env` editieren — mindestens setzen:
- `WP_HOST`, `MOODLE_HOST`, `TRAEFIK_HOST`
- alle Passwörter (`*_PASSWORD`, `*_PASS`)
- `ACME_EMAIL`
- `TRAEFIK_TLS_RESOLVER` (`letsencrypt-staging` für lokal/hinter Firewall, `letsencrypt` für Produktion)
- SMTP-Daten

### 2. Dashboard-Passwort generieren

```bash
htpasswd -nbB admin "<dein-passwort>" | sed -e 's/\$/\$\$/g'
```

**Wichtig:** Jedes `$` im Hash muss im `.env`-File **verdoppelt** werden (Compose interpoliert sonst), deshalb der `sed`-Befehl. Den Output-Teil **nach dem `:`** in `.env` als `TRAEFIK_DASHBOARD_PASSWORD_HASH=...` setzen.

### 3. ACME-Storage anlegen

```bash
touch traefik/acme.json traefik/acme-staging.json
chmod 600 traefik/acme.json traefik/acme-staging.json
```

### 4. Datenverzeichnisse anlegen

```bash
mkdir -p data/wp/content data/wp/db
mkdir -p data/moodle/moodledata data/moodle/db
```

### 5. Proxy-Netzwerk anlegen

```bash
docker network create proxy
```

### 6. Start

```bash
docker compose up -d --build
```

Der erste Start dauert mehrere Minuten — die Moodle-Quellen werden im Image aus Git geklont, und die WordPress-Plugins werden heruntergeladen.

Logs verfolgen:
```bash
docker compose logs -f wp-php moodle-php
```

Nach dem ersten Erfolg sind erreichbar:
- WordPress: `https://${WP_HOST}` — Login mit `WP_ADMIN_USER` / `WP_ADMIN_PASS`
- Moodle: `https://${MOODLE_HOST}` — Login mit `MOODLE_ADMIN_USER` / `MOODLE_ADMIN_PASS`
- Traefik-Dashboard: `https://${TRAEFIK_HOST}` — Basic-Auth aus `.env`

## Stoppen

```bash
docker compose down
```

## Updates

### WordPress, BuddyPress, oder eigene Plugins

`.env` ändern (`WP_VERSION`, `BUDDYPRESS_VERSION`, `JITSI_SPARRING_REF`, `MEETING_REGISTRATION_REF`), dann:

```bash
docker compose build --no-cache wp-php wp-nginx wp-cron
docker compose up -d
```

Beim nächsten Start synchronisiert der Entrypoint die neuen Plugin-Versionen in den `data/wp/content/plugins/`-Bind-Mount.

### Moodle

```bash
# .env: MOODLE_GIT_BRANCH anpassen
docker compose build --no-cache moodle-php moodle-nginx moodle-cron
docker compose up -d
# Bei größeren Versionssprüngen:
docker compose exec moodle-php php admin/cli/upgrade.php --non-interactive
```

## Komplett zurücksetzen

```bash
docker compose down
sudo rm -rf data/wp data/moodle
docker compose up -d
```

**Wichtig:** Sowohl `content`/`moodledata` als auch `db` löschen — sonst sieht der Entrypoint die alte DB und überspringt den Install.

## Stolperstellen

1. **Passwort-Hash-Escaping:** Jedes `$` im `TRAEFIK_DASHBOARD_PASSWORD_HASH` muss als `$$` geschrieben werden.
2. **Versions-Variablen wirken erst nach `--no-cache`-Rebuild.**
3. **LE-Staging-Zertifikate sind im Browser nicht vertrauenswürdig.** Für lokales Testen normal; für Produktion `TRAEFIK_TLS_RESOLVER=letsencrypt` setzen.
4. **Erster Start dauert lange** (Moodle-Git-Clone, Plugin-Downloads). Mit `docker compose logs -f` beobachten.

## Datenstruktur

```
data/
├── wp/
│   ├── content/    # WordPress wp-content (uploads, themes, languages, mu-plugins)
│   └── db/         # WordPress MariaDB
└── moodle/
    ├── moodledata/ # Moodle-Daten
    └── db/         # Moodle MariaDB
```

Backup eines kompletten Datensatzes: `tar czf backup-$(date +%Y%m%d).tar.gz data/ traefik/acme*.json`.
```

- [ ] **Step 2: Verify rendering**

```bash
head -30 README.md
```
Expected: well-formatted markdown.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: German setup README"
```

---

## Task 16: Final integration verification

This task makes no code changes — it's a clean-room verification of the whole stack from scratch.

- [ ] **Step 1: Reset everything**

```bash
docker compose down -v
sudo rm -rf data
mkdir -p data/wp/content data/wp/db data/moodle/moodledata data/moodle/db
> traefik/acme.json
> traefik/acme-staging.json
chmod 600 traefik/acme.json traefik/acme-staging.json
```

- [ ] **Step 2: Build and start everything**

```bash
docker compose up -d --build
```

- [ ] **Step 3: Wait for both apps to finish installing**

```bash
docker compose logs -f wp-php moodle-php
```
Wait for both "Success: WordPress installed" and Moodle's install-complete messages. Ctrl-C.

- [ ] **Step 4: All-services smoke test**

```bash
# WordPress
curl -ksI https://community.localtest.me | head -3
# Expected: HTTP/2 200 or 302

# Moodle
curl -ksI https://lms.localtest.me | head -3
# Expected: HTTP/2 200 or 303

# Traefik dashboard (unauth → 401)
curl -ksI https://traefik.localtest.me | head -3
# Expected: HTTP/2 401

# Traefik dashboard (auth → 200)
curl -ksI -u admin:test1234 https://traefik.localtest.me | head -3
# Expected: HTTP/2 200
```

- [ ] **Step 5: Verify all 9 containers are healthy**

```bash
docker compose ps
```
Expected: 9 rows, all with status `Up` (or `Up (healthy)` if health checks exist).

- [ ] **Step 6: Tear down (leave data for the user)**

```bash
docker compose down
```

- [ ] **Step 7: Final commit**

If anything was adjusted during this final pass:
```bash
git status
git add <files>
git commit -m "fix: final integration adjustments"
```
Otherwise no commit needed.

- [ ] **Step 8: Tag the milestone**

```bash
git tag -a v0.1.0 -m "Initial stack: WordPress + BuddyPress + custom plugins + Moodle + Traefik"
```

---

## Done.

After Task 16, the engineer should hand off:

- A green `docker compose ps` showing 9 running services
- All three URLs accessible via curl
- `data/` populated with WP + Moodle persistent data
- A clean `git log` with one commit per task
- The `v0.1.0` tag
