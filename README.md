# CoPAI Community-Plattform

Ein Docker-Compose-Stack mit WordPress (+ BuddyPress + zwei eigene Plugins), Moodle, und Traefik als Reverse-Proxy. Konfiguration läuft komplett über die zentrale `.env`-Datei im Root.

## Architektur

13 Container in 4 Netzwerken:

- **traefik** — Reverse-Proxy mit Let's Encrypt (Staging oder Produktion umschaltbar)
- **wp-php**, **wp-nginx**, **wp-cron**, **wp-db** — WordPress-Stack auf `wp-net`
- **moodle-php**, **moodle-nginx**, **moodle-cron**, **moodle-db** — Moodle-Stack auf `moodle-net`
- **jitsi-web**, **jitsi-prosody**, **jitsi-jicofo**, **jitsi-jvb** — Jitsi-Meet-Stack auf `jitsi-net`

Nur die drei nginx-/jitsi-web-Container hängen zusätzlich am `proxy`-Netz und sind damit für Traefik sichtbar. Datenbanken und interne Jitsi-Komponenten sind von außen nicht erreichbar.

## Single Sign-On WordPress → Moodle (OAuth2)

WordPress agiert als OAuth2-Identity-Provider, Moodle als OAuth2-Client. Anmeldung mit WP-Credentials auf Moodle ist out-of-the-box konfiguriert.

**Endpoints (auf WP-Seite):**

- `https://${WP_HOST}/wp-json/copai-oauth/v1/.well-known/openid-configuration` — OIDC-Discovery
- `https://${WP_HOST}/wp-json/copai-oauth/v1/authorize` — Authorization
- `https://${WP_HOST}/wp-json/copai-oauth/v1/token` — Token Exchange
- `https://${WP_HOST}/wp-json/copai-oauth/v1/userinfo` — User-Profil

**Login-Flow:**

1. User öffnet `https://${MOODLE_HOST}/login/` → klickt Button "CoPAI Community"
2. Moodle leitet zum WP-`/authorize`-Endpoint um
3. Falls noch nicht in WP eingeloggt: WP-Login-Seite (mit Rück-Redirect)
4. Nach Login: WP gibt Code aus → Redirect zu Moodle-Callback
5. Moodle tauscht Code gegen Token, ruft `userinfo` auf
6. Moodle legt Account (per Email) an oder findet ihn, loggt ein

**Konfiguration (`.env`):**

- `OAUTH_CLIENT_ID` (default `moodle`)
- `OAUTH_CLIENT_SECRET` — **muss vor Produktiveinsatz rotiert werden** (`openssl rand -hex 24`)

**Field-Mapping**: `email→email`, `given_name→firstname`, `family_name→lastname`, `preferred_username→username`.

**Wer einen WP-Account hat, kann sich auf Moodle einloggen.** WP-Admin-Klick-Konfiguration ist NICHT erforderlich — beim Container-Start läuft `moodle/setup_oauth2.php` idempotent und legt Issuer/Endpoints/Mappings an.

## Jitsi-Spezifika

- **JVB-UDP-Port 10000** wird direkt auf dem Host exponiert (von Traefik unabhängig). Für Video/Audio brauchen Browser eine direkte UDP-Verbindung zum Host auf `${JITSI_JVB_ADVERTISE_IPS}:10000`.
- **Lokal** (Browser auf gleichem Host): `JITSI_JVB_ADVERTISE_IPS=127.0.0.1` → funktioniert ohne weitere Konfiguration.
- **LAN-Test**: `JITSI_JVB_ADVERTISE_IPS` auf die LAN-IP setzen, UDP/10000 auf dem Host nicht firewallen.
- **Produktion**: `JITSI_JVB_ADVERTISE_IPS` = öffentliche IPv4, UDP/10000 im Router/Firewall forwarden.
- **bp-jitsi-sparring**: Wird beim Start automatisch auf `https://${JITSI_HOST}` gepointet (nur wenn die Option leer ist oder noch auf `meet.jit.si` zeigt).
- **Interne Auth-Secrets** (`JITSI_JICOFO_*`, `JITSI_JVB_AUTH_PASSWORD`): vor Produktiveinsatz unbedingt rotieren — `openssl rand -hex 16`.

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
mkdir -p data/jitsi/{prosody-config,prosody-plugins,jicofo,jvb,web,transcripts}
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

### Vorkonfiguriertes Menü

Beim ersten Start wird automatisch ein WP-Menü "Hauptmenü" mit drei Einträgen angelegt:

| Eintrag | Ziel |
|---|---|
| Startseite | `/` |
| Meetups | `/meetups/` (Archiv des `meetup`-Custom-Post-Types) |
| Mitglieder | `/members/` (BuddyPress) |

Bei **klassischen Themes** wird das Menü automatisch an die `primary`-Location gebunden und ist sofort sichtbar.

Bei **Block-Themes** (Default `twentytwentyfive`) ist das Menü nur in der DB hinterlegt — der Navigation-Block muss einmalig darauf zeigen:

1. WP-Admin → Design → Editor → Header (oder die Vorlage, die die Navigation enthält)
2. Auf den Navigation-Block klicken → drei Punkte → **Vorhandenes Menü auswählen** → **Hauptmenü**
3. Speichern

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

### Förderhinweis

Dieses Projekt wurde im Rahmen des Erasmus+-Programms der Europäischen Union gefördert:

- **Projekt:** Community of Practice AI
- **Projekt-Nr.:** 2023-2-AT01-KA210-VET-000169864
- **Träger:** Murbit GmbH

> **Disclaimer:** Funded by the European Union. Views and opinions expressed are however those of the author(s) only and do not necessarily reflect those of the European Union or the European Education and Culture Executive Agency (EACEA). Neither the European Union nor EACEA can be held responsible for them.

<img src="https://www.copai.community/wp-content/uploads/2024/04/DE_Co-fundedbytheEU_RGB_POS-1024x225.png" alt="Co-funded by the European Union" width="200">

### Kontakt

- Web: <https://copai.community>
- Code-Repository: siehe `README.md`
- Maintainer: Murbit GmbH
