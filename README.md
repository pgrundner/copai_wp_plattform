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
- `htpasswd` (Paket `apache2-utils` auf Debian/Ubuntu, `httpd-tools` auf RHEL, auf macOS standardmäßig vorhanden)
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

**Wichtig:** Jedes `$` im Hash muss in `.env` **verdoppelt** werden (Compose interpoliert sonst), deshalb der `sed`-Befehl. Den Output-Teil **nach dem `:`** in `.env` als `TRAEFIK_DASHBOARD_PASSWORD_HASH=...` setzen.

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

### Lokales Testen ohne DNS

Falls `${WP_HOST}` etc. auf `*.localtest.me` zeigen, löst macOS das nicht automatisch auf. Entweder:

```bash
# /etc/hosts ergänzen:
127.0.0.1 community.localtest.me lms.localtest.me traefik.localtest.me
```

Oder mit `curl --resolve` testen:

```bash
curl -ksI --resolve community.localtest.me:443:127.0.0.1 https://community.localtest.me
```

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
rm -rf data/wp data/moodle
docker compose up -d
```

**Wichtig:** Sowohl `content`/`moodledata` als auch `db` löschen — sonst sieht der Entrypoint die alte DB und überspringt den Install.

## Stolperstellen

1. **Passwort-Hash-Escaping:** Jedes `$` im `TRAEFIK_DASHBOARD_PASSWORD_HASH` muss als `$$` geschrieben werden.
2. **Versions-Variablen wirken erst nach `--no-cache`-Rebuild.**
3. **LE-Staging-Zertifikate sind im Browser nicht vertrauenswürdig.** Für lokales Testen normal; für Produktion `TRAEFIK_TLS_RESOLVER=letsencrypt` setzen.
4. **Erster Start dauert lange** (Moodle-Git-Clone, Plugin-Downloads). Mit `docker compose logs -f` beobachten.
5. **Plugin-Branches:** `JITSI_SPARRING_REF` und `MEETING_REGISTRATION_REF` müssen Branches/Tags sein, die im jeweiligen GitHub-Repo existieren. Default ist `master` (das ist HEAD beider Repos).

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
