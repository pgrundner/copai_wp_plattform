# Handbuch — CoPAI Community-Plattform

**Projekt:** Community of Practice AI (CoPAI)
**Förderprogramm:** Erasmus+ · Projekt-Nr. **KA210-VET-4603C73C**
**Träger / Entwicklung:** Murbit GmbH
**Web:** <https://copai.community>
**Stand:** Mai 2026

> Dieses Handbuch beschreibt vollständig, wie die CoPAI-Community-Plattform installiert, konfiguriert, angepasst und mit Inhalten gefüllt wird. Es richtet sich an Prüfer:innen des Erasmus+-Projekts sowie an spätere Betreiber:innen — Linux-Grundkenntnisse genügen.

---

## Inhaltsverzeichnis

1. [Was ist die CoPAI-Plattform?](#1-was-ist-die-copai-plattform)
2. [Systemvoraussetzungen](#2-systemvoraussetzungen)
3. [Schnellstart (TL;DR)](#3-schnellstart-tldr)
4. [Detaillierte Installation](#4-detaillierte-installation)
5. [Konfiguration über die `.env`-Datei](#5-konfiguration-über-die-env-datei)
6. [Erste Schritte nach der Installation](#6-erste-schritte-nach-der-installation)
7. [Customisierung — Design, Sprache, Branding](#7-customisierung--design-sprache-branding)
8. [Inhalte einfügen — Seiten, Meetups, Mitglieder, Kurse](#8-inhalte-einfügen--seiten-meetups-mitglieder-kurse)
9. [Single Sign-On WordPress → Moodle](#9-single-sign-on-wordpress--moodle)
10. [Wartung, Updates & Backups](#10-wartung-updates--backups)
11. [Fehlersuche (Troubleshooting)](#11-fehlersuche-troubleshooting)
12. [Architekturüberblick](#12-architekturüberblick)
13. [Lizenz & Förderhinweis](#13-lizenz--förderhinweis)

---

## 1. Was ist die CoPAI-Plattform?

Die CoPAI-Plattform ist eine integrierte Community-of-Practice-Umgebung, die im Rahmen des Erasmus+-Projekts **Community of Practice AI** entstanden ist. Sie kombiniert vier Bausteine zu einer Einheit:

| Baustein | Aufgabe |
|---|---|
| **WordPress + BuddyPress** | Öffentliche Community-Website: Mitglieder, Profile, Gruppen, Aktivitätsstrom, Meetup-Listen |
| **Moodle** | Lernmanagement-System (LMS) für Kurse, Aufgaben, Zertifikate |
| **Jitsi Meet** | Eigener Video­konferenz­server für Meetups und virtuelle Treffen |
| **Traefik** | Reverse-Proxy mit automatischer HTTPS-Zertifizierung (Let's Encrypt) |

Alle Bausteine laufen als Docker-Container auf einem einzigen Host und werden durch eine **einzige Konfigurationsdatei** (`.env`) gesteuert. Eine Benutzer:in, die sich auf der WordPress-Community registriert, kann sich mit denselben Zugangsdaten in Moodle einloggen (Single Sign-On per OAuth2).

### Architektur in einem Bild

```
                  Internet  ───►  Port 80 / 443
                                     │
                              ┌──────▼──────┐
                              │   Traefik   │  (HTTPS, Let's Encrypt)
                              └──┬───┬───┬──┘
                  community.…  │   │   │  lms.…
                               │   │   │
                  ┌────────────▼┐  │   └─►┌──────────┐
                  │ WordPress + │  │      │  Moodle  │
                  │ BuddyPress  │  │      │   LMS    │
                  └─────────────┘  │      └──────────┘
                                   │
                          meet.…   │
                              ┌────▼──────┐
                              │ Jitsi Meet│
                              └───────────┘
```

---

## 2. Systemvoraussetzungen

### 2.1 Hardware (empfohlen)

| Ressource | Minimum (Test) | Empfohlen (Produktion mit ~100 Nutzer:innen) |
|---|---|---|
| **CPU** | 2 vCPU | 4 vCPU |
| **RAM** | 4 GB | 8 GB |
| **Festplatte** | 20 GB SSD | 80 GB SSD |
| **Netzwerk** | 10 Mbit/s | 100 Mbit/s, statische IPv4 |

Jitsi-Videokonferenzen sind bandbreitenintensiv; jeder Teilnehmer benötigt ca. 1–2 Mbit/s Upload **am Server**.

### 2.2 Betriebssystem

- Linux (Ubuntu 22.04 LTS oder neuer, Debian 12, Rocky/Alma 9 — empfohlen)
- macOS (für lokale Tests, nicht für Produktion)
- Windows: nur über WSL 2 möglich, wird nicht offiziell unterstützt

### 2.3 Software (auf dem Host)

| Werkzeug | Mindestversion | Installationshinweis |
|---|---|---|
| **Docker Engine** | 24.x | <https://docs.docker.com/engine/install/> |
| **Docker Compose Plugin** | v2.x | i. d. R. mit Docker Engine mitgeliefert (`docker compose version`) |
| **htpasswd** | beliebig | Debian/Ubuntu: `sudo apt install apache2-utils`; macOS: bereits vorhanden |
| **git** | 2.x | nur für `git clone` dieses Repos benötigt |
| **openssl** | beliebig | für Passwortgenerierung |

### 2.4 Netzwerk & DNS

**Produktivbetrieb:**
- Öffentlich erreichbare IPv4-Adresse
- Drei DNS-A-Records, die alle auf diese IP zeigen, z. B.:
  - `community.copai.community` (WordPress)
  - `lms.copai.community` (Moodle)
  - `meet.copai.community` (Jitsi)
  - Optional: `traefik.copai.community` (Admin-Dashboard)
- Folgende Ports müssen aus dem Internet erreichbar sein:
  - **TCP 80** (HTTP → wird auf HTTPS umgeleitet)
  - **TCP 443** (HTTPS)
  - **UDP 10000** (Jitsi-Videostream)

**Lokaler Test ohne DNS:**
- Die `.env` ist standardmäßig auf `*.localtest.me` konfiguriert. Diese Domain löst weltweit auf `127.0.0.1` auf und ist ideal für lokale Tests.
- Unter macOS muss ggf. `/etc/hosts` ergänzt werden (siehe Abschnitt [Fehlersuche](#11-fehlersuche-troubleshooting)).

### 2.5 Externer SMTP-Server

Die Plattform versendet E-Mails (Registrierungs­bestätigungen, Passwort-Reset, Moodle-Benachrichtigungen). Sie benötigen einen erreichbaren SMTP-Server — z. B. den eigenen Mailserver der Organisation, einen Mail-Provider (Postmark, SendGrid, Mailjet) oder einen Office-365/Google-Workspace-Account.

---

## 3. Schnellstart (TL;DR)

Für eine erfahrene Administrator:in genügen folgende Schritte:

```bash
git clone <repository-url> copai
cd copai
cp env.example .env
# .env bearbeiten — siehe Abschnitt 5
htpasswd -nbB admin "<wunschpasswort>" | sed -e 's/\$/\$\$/g'   # Hash in .env eintragen
touch traefik/acme.json traefik/acme-staging.json
chmod 600 traefik/acme.json traefik/acme-staging.json
mkdir -p data/wp/{content,db} data/moodle/{moodledata,db} data/jitsi/{prosody-config,prosody-plugins,jicofo,jvb,web,transcripts}
docker network create proxy
docker compose up -d --build
```

Der erste Start dauert je nach Leitung **5 bis 15 Minuten** (Moodle-Quellcode wird aus Git geklont, WordPress-Plugins werden heruntergeladen).

---

## 4. Detaillierte Installation

### Schritt 1 — Projekt herunterladen

```bash
git clone <repository-url> copai
cd copai
```

Alternativ kann das Projekt als ZIP heruntergeladen und entpackt werden.

### Schritt 2 — Konfigurationsdatei anlegen

```bash
cp env.example .env
```

Öffnen Sie die `.env` mit einem Texteditor (`nano .env`, `vim .env` oder grafisch). Die wichtigsten Variablen sind in [Abschnitt 5](#5-konfiguration-über-die-env-datei) erklärt.

### Schritt 3 — Passwort-Hash für das Traefik-Dashboard erzeugen

Das Traefik-Admin-Dashboard ist mit Basic-Auth geschützt. Erzeugen Sie den Passwort-Hash:

```bash
htpasswd -nbB admin "<wunschpasswort>" | sed -e 's/\$/\$\$/g'
```

Der Befehl gibt etwa Folgendes aus:

```
admin:$$2y$$05$$5pBHU...$$Q7tcFf3OmRJxYz9
```

Übernehmen Sie **alles nach dem Doppelpunkt** in die `.env` als Wert von `TRAEFIK_DASHBOARD_PASSWORD_HASH`.

> **Wichtig:** Jedes `$` muss in der `.env` als `$$` geschrieben werden. Das macht der `sed`-Befehl automatisch. Verändern Sie den Hash nicht von Hand.

### Schritt 4 — Speicherorte für TLS-Zertifikate vorbereiten

```bash
touch traefik/acme.json traefik/acme-staging.json
chmod 600 traefik/acme.json traefik/acme-staging.json
```

Die `acme.json`-Dateien speichern die von Let's Encrypt ausgestellten Zertifikate. Die Berechtigung **muss** `600` sein, sonst weigert sich Traefik zu starten.

### Schritt 5 — Datenverzeichnisse anlegen

```bash
mkdir -p data/wp/content data/wp/db
mkdir -p data/moodle/moodledata data/moodle/db
mkdir -p data/jitsi/{prosody-config,prosody-plugins,jicofo,jvb,web,transcripts}
```

Alle persistenten Daten der Plattform landen unter `data/`. Backups erfassen genau diesen Ordner (siehe [Abschnitt 10](#10-wartung-updates--backups)).

### Schritt 6 — Externes Docker-Netzwerk anlegen

```bash
docker network create proxy
```

Dieses Netzwerk verbindet Traefik mit allen Web-Diensten und wird einmalig manuell angelegt.

### Schritt 7 — Plattform bauen und starten

```bash
docker compose up -d --build
```

`-d` startet im Hintergrund, `--build` baut die eigenen Images (WordPress + Plugins, Moodle aus Git). Der **erste Start dauert mehrere Minuten** — folgen Sie dem Fortschritt:

```bash
docker compose logs -f wp-php moodle-php
```

Sobald in den Logs `WordPress installiert.` und `Moodle install complete` erscheinen, ist die Plattform betriebsbereit.

### Schritt 8 — Plattform aufrufen

| Dienst | URL (Standard `.env`) |
|---|---|
| Community / WordPress | `https://community.localtest.me` |
| Lernplattform / Moodle | `https://lms.localtest.me` |
| Videokonferenz / Jitsi | `https://meet.localtest.me` |
| Traefik-Dashboard | `https://traefik.localtest.me` |

Login mit den in der `.env` gesetzten Admin-Zugangsdaten.

> **Bei lokalem Test mit Let's-Encrypt-Staging warnt der Browser vor unsicherem Zertifikat.** Das ist erwartet — siehe [Fehlersuche](#11-fehlersuche-troubleshooting).

---

## 5. Konfiguration über die `.env`-Datei

Die gesamte Plattform wird durch **eine einzige Datei** konfiguriert: `.env` im Projekt-Root. Sie ist nach Bereichen gegliedert.

### 5.1 Globale Werte

```ini
COMPOSE_PROJECT_NAME=copai      # Präfix für alle Container-Namen
TZ=Europe/Berlin                # Zeitzone
```

### 5.2 Traefik (Reverse-Proxy)

```ini
TRAEFIK_HOST=traefik.localtest.me                  # URL des Admin-Dashboards
TRAEFIK_DASHBOARD_USER=admin                       # Login-Name für Dashboard
TRAEFIK_DASHBOARD_PASSWORD_HASH=$$apr1$$...        # Passwort-Hash (siehe Installation)
ACME_EMAIL=ihre-adresse@example.com                # E-Mail an Let's Encrypt
TRAEFIK_TLS_RESOLVER=letsencrypt-staging           # oder: letsencrypt
TRAEFIK_NETWORK=proxy                              # Docker-Netzwerk-Name
```

Schalter **`TRAEFIK_TLS_RESOLVER`:**
- `letsencrypt-staging` — Testzertifikate (für lokal/Tests). Browser warnt, aber kein Rate-Limit-Risiko.
- `letsencrypt` — produktive, gültige Zertifikate. **Nur** verwenden, wenn die Hostnamen wirklich öffentlich auflösen!

### 5.3 WordPress

```ini
WP_HOST=community.localtest.me        # Öffentliche URL
WP_VERSION=6.9                         # WordPress-Version (Rebuild nötig)
WP_PHP_VERSION=8.2
WP_TITLE=CoPAI Community               # Webseiten-Titel
WP_ADMIN_USER=admin
WP_ADMIN_PASS=changeme                 # ⚠ vor Produktiv-Einsatz ändern
WP_ADMIN_EMAIL=admin@example.com
WP_MAX_UPLOAD=64M                      # Max. Dateigröße im Upload
```

Datenbank-Zugangsdaten (werden beim Erststart automatisch verwendet):

```ini
WP_DB_NAME=wordpress
WP_DB_USER=wordpress
WP_DB_PASSWORD=changeme                # ⚠ vor Produktiv-Einsatz ändern
WP_DB_ROOT_PASSWORD=changeme           # ⚠ ändern
```

Beschriftung des automatisch erzeugten Hauptmenüs:

```ini
WP_MENU_HOME_LABEL=Startseite
WP_MENU_MEETUPS_LABEL=Meetups
WP_MENU_MEMBERS_LABEL=Mitglieder
```

Plugin-Versionen (gepinnt für Reproduzierbarkeit):

```ini
BUDDYPRESS_VERSION=14.0.0              # Aus dem WP-Plugin-Repository
JITSI_SPARRING_REF=master              # Branch/Tag der Murbit-Eigenentwicklung
MEETING_REGISTRATION_REF=master
```

### 5.4 SMTP (externer Mailversand)

```ini
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USER=                             # leer = kein Auth
SMTP_PASS=
SMTP_FROM_EMAIL=no-reply@example.com
SMTP_FROM_NAME=CoPAI Community
SMTP_ENCRYPTION=tls                    # tls | ssl | none
```

Diese Einstellungen werden **sowohl in WordPress als auch in Moodle** verwendet — beide Systeme nutzen denselben Mailserver.

### 5.5 Single Sign-On

```ini
OAUTH_CLIENT_ID=moodle
OAUTH_CLIENT_SECRET=change-me-oauth-shared-secret
```

> **Pflicht vor Produktiveinsatz:** Erzeugen Sie ein neues Secret mit `openssl rand -hex 24` und tragen Sie es ein.

### 5.6 Jitsi

```ini
JITSI_HOST=meet.localtest.me
JITSI_VERSION=stable
JITSI_JVB_ADVERTISE_IPS=127.0.0.1      # siehe unten

# Interne Geheimnisse — vor Produktiv-Einsatz erneuern:
JITSI_JICOFO_COMPONENT_SECRET=...      # openssl rand -hex 16
JITSI_JICOFO_AUTH_PASSWORD=...
JITSI_JVB_AUTH_PASSWORD=...
```

`JITSI_JVB_ADVERTISE_IPS` ist je nach Einsatzszenario zu setzen:

| Szenario | Wert |
|---|---|
| Lokaler Test (Browser auf demselben Rechner) | `127.0.0.1` |
| LAN-Test | LAN-IP des Servers (z. B. `192.168.1.42`) |
| Produktion | öffentliche IPv4-Adresse des Servers |

### 5.7 Moodle

```ini
MOODLE_HOST=lms.localtest.me
MOODLE_GIT_BRANCH=MOODLE_404_STABLE    # Moodle-Versionszweig
MOODLE_ADMIN_USER=admin
MOODLE_ADMIN_PASS=changeme             # ⚠ ändern
MOODLE_ADMIN_EMAIL=admin@example.com
MOODLE_MAX_UPLOAD=512M

MOODLE_DB_NAME=moodle
MOODLE_DB_USER=moodle
MOODLE_DB_PASSWORD=changeme            # ⚠ ändern
MOODLE_DB_ROOT_PASSWORD=changeme       # ⚠ ändern
```

---

## 6. Erste Schritte nach der Installation

### 6.1 WordPress einrichten

1. Öffnen Sie `https://${WP_HOST}/wp-admin` und melden sich mit `WP_ADMIN_USER` / `WP_ADMIN_PASS` an.
2. Beim Erststart ist automatisch das Hauptmenü mit den drei Standardeinträgen (**Startseite, Meetups, Mitglieder**) angelegt.
3. **Bei Block-Themes** (Standard: *Twenty Twenty-Five*) ist das Menü zwar in der Datenbank, aber noch nicht im Header-Block sichtbar:
   - **Design → Editor → Header**
   - Auf den Navigation-Block klicken → ⋮ → **Vorhandenes Menü auswählen → Hauptmenü**
   - **Speichern**
4. Optional: **Einstellungen → Allgemein** — Titel, Untertitel, Sprache, Wochenstart anpassen.

### 6.2 BuddyPress aktivieren

BuddyPress ist bereits installiert. Unter **Einstellungen → BuddyPress** können Sie wählen, welche Komponenten aktiv sein sollen (Mitgliederprofile, Gruppen, Aktivitätsstrom, …). Die Voreinstellungen sind sinnvoll und können später jederzeit geändert werden.

### 6.3 Moodle einrichten

1. Öffnen Sie `https://${MOODLE_HOST}` und melden sich mit `MOODLE_ADMIN_USER` / `MOODLE_ADMIN_PASS` an.
2. **Website-Administration → Allgemein → Standort-Einstellungen** — Zeitzone und Standardsprache prüfen.
3. **Website-Administration → Sprache → Sprachpakete installieren** — bei Bedarf Deutsch, weitere Sprachen ergänzen.
4. Der Single-Sign-On-Button **„CoPAI Community"** ist auf der Login-Seite bereits sichtbar — er wurde durch das Skript `moodle/setup_oauth2.php` automatisch konfiguriert.

### 6.4 Jitsi prüfen

Öffnen Sie `https://${JITSI_HOST}` — Sie sollten die Jitsi-Startseite sehen und einen Konferenzraum betreten können. Bei Audio-/Video-Problemen siehe [Fehlersuche](#11-fehlersuche-troubleshooting).

---

## 7. Customisierung — Design, Sprache, Branding

### 7.1 WordPress-Theme wechseln

Standardmäßig läuft das WordPress-Block-Theme *Twenty Twenty-Five*. Sie können jedes andere WordPress-Theme installieren:

1. **Design → Themes → Neues Theme hinzufügen**
2. Theme suchen, **Installieren → Aktivieren**

Empfohlen für Community-Plattformen:
- **BuddyX** — speziell für BuddyPress optimiert (kostenfrei + Pro-Version)
- **Astra**, **GeneratePress** — sehr schnell, breite Anpassbarkeit
- **Block-Themes** (z. B. *Twenty Twenty-Five*) — modernster Ansatz mit visuellem Editor

### 7.2 Logo, Farben & Schriften

Bei Block-Themes:
- **Design → Editor → Stile** — globale Farben, Typografie, Abstände
- **Design → Editor → Vorlagen → Header** — Logo, Navigation, Suche einfügen

Bei klassischen Themes:
- **Design → Customizer** (Live-Vorschau)
- **Design → Customizer → Site-Identität** — Logo, Site-Icon, Titel

### 7.3 Mehrsprachigkeit

WordPress + BuddyPress unterstützen Sprachpakete out-of-the-box. Unter **Einstellungen → Allgemein → Website-Sprache** wählen Sie die Hauptsprache. Für **mehrere parallele Sprachen** empfehlen wir das Plugin **Polylang** oder **TranslatePress** — beide kompatibel mit BuddyPress.

In Moodle: **Website-Administration → Sprache → Sprachpakete installieren** und unter **Standardsprache** umschalten. Benutzer:innen können ihre Sprache im Profil individuell wählen.

### 7.4 Eigene WordPress-Plugins

Die CoPAI-Plattform enthält zwei selbst entwickelte Plugins der Murbit GmbH:

| Plugin | Zweck | Pfad |
|---|---|---|
| **CoPAI Meetup Block** | Gutenberg-Block „Meetup-Liste", Archive-Seite für den Meetup-CPT | `wordpress/plugins/copai-meetup-block/` |
| **CoPAI OAuth2 Server** | OAuth2-Identity-Provider für Single Sign-On mit Moodle | `wordpress/plugins/copai-oauth-server/` |

Außerdem werden zwei externe Murbit-Plugins beim Build aus GitHub gezogen:

| Plugin | Zweck | Referenz |
|---|---|---|
| **CoPAI BuddyPress Jitsi Sparring** | Bindet Jitsi-Konferenz­räume in BuddyPress-Gruppen ein | `JITSI_SPARRING_REF` |
| **CoPAI Meeting Registration** | Anmeldung zu Meetups, Teilnehmerverwaltung | `MEETING_REGISTRATION_REF` |

Um eine andere Version zu nutzen: `.env` editieren (`JITSI_SPARRING_REF=v1.2.0`), dann:

```bash
docker compose build --no-cache wp-php wp-cron
docker compose up -d
```

### 7.5 Anpassung der eigenen Plugins

Alle vier Plugins sind quelloffen (MIT-Lizenz). Anpassungen direkt im Container sind möglich, aber wir empfehlen den klassischen Weg:

1. Plugin forken (auf GitHub) oder lokal anpassen.
2. `JITSI_SPARRING_REF` / `MEETING_REGISTRATION_REF` in `.env` auf den eigenen Branch/Tag setzen.
3. Rebuild + Start (siehe oben).

Für die direkt im Repo liegenden Plugins (`copai-meetup-block`, `copai-oauth-server`): Code in `wordpress/plugins/<plugin>/` ändern und neu bauen.

### 7.6 Moodle-Branding

- **Website-Administration → Darstellung → Logos** — Header-Logo, Compact-Logo, Favicon
- **Website-Administration → Darstellung → Themes → Boost (oder Theme-Auswahl)** — Hauptfarbe, Login-Hintergrund, eigenes CSS
- Eigene Themes von <https://moodle.org/plugins/> nachinstallierbar.

### 7.7 Jitsi-Branding

Anpassbar über Umgebungsvariablen in der `compose.yml` (Service `jitsi-web`). Häufig genutzte Variablen:

| Variable | Wirkung |
|---|---|
| `INTERFACE_DEFAULT_LOGO_URL` | Logo links oben |
| `INTERFACE_SHOW_JITSI_WATERMARK=0` | Jitsi-Wasserzeichen ausblenden |
| `INTERFACE_APP_NAME=CoPAI Meet` | Name in der Browser-Titelleiste |

Nach Änderungen: `docker compose up -d jitsi-web`.

---

## 8. Inhalte einfügen — Seiten, Meetups, Mitglieder, Kurse

### 8.1 Statische Seiten (WordPress)

1. **Seiten → Erstellen** — Titel und Inhalt im Gutenberg-Editor erstellen.
2. **Veröffentlichen**.
3. Die Seite kann anschließend in das Menü aufgenommen werden (**Design → Menüs** bzw. im Block-Theme im Header-Block).

### 8.2 Mitglieder-Verwaltung (BuddyPress)

Mitglieder können sich selbst registrieren (`/register`). Admin-Funktionen:

- **Benutzer → Alle Benutzer** — Liste, Rollenwechsel, Löschen.
- **BuddyPress → Gruppen** — Themen-Gruppen anlegen (mit oder ohne Jitsi-Sparring-Raum).
- **BuddyPress → Komponenten** — Aktivierung/Deaktivierung einzelner Funktionen (Foren, private Nachrichten, …).

### 8.3 Meetups anlegen

Die Plattform stellt einen **Custom Post Type „Meetup"** zur Verfügung (via Plugin **CoPAI Meeting Registration**).

1. **Meetups → Erstellen** im WordPress-Backend.
2. Titel, Beschreibung, **Datum/Uhrzeit**, Ort (Adresse oder „Online via Jitsi") eintragen.
3. Optional: maximale Teilnehmerzahl, Anmeldeschluss, Teilnahmegebühr.
4. **Veröffentlichen**.

Die Meetup-Liste erscheint automatisch unter `/meetups/`. Den Gutenberg-Block **„Meetup-Liste"** können Sie auf beliebigen Seiten einsetzen, um z. B. die nächsten drei Meetups auf der Startseite anzuzeigen.

### 8.4 Kurse anlegen (Moodle)

1. Einloggen unter `https://${MOODLE_HOST}` als Admin.
2. **Website-Administration → Kurse → Kursbereiche** — Themen-Bereiche anlegen (z. B. „Grundlagen KI", „Anwendungen").
3. **Website-Administration → Kurse → Kurs hinzufügen** — Kurs anlegen.
4. Im Kurs selbst: **Bearbeiten einschalten** → Lektionen, Aufgaben, Tests, Foren, Videos hinzufügen.
5. **Teilnehmer → Nutzer:innen einschreiben** oder Selbsteinschreibung aktivieren.

Bewährte Moodle-Aktivitäten für CoPAI-Kurse:
- **Buch / Lektion / Seite** — strukturierter Lernstoff
- **Quiz** — Wissens­überprüfung mit automatischer Auswertung
- **Aufgabe** — Datei-Abgaben mit Bewertung
- **Forum** — Diskussionen unter Teilnehmer:innen
- **H5P** — interaktive Inhalte
- **Zertifikat** — automatisierte Teilnahmebestätigungen (Plugin „Custom certificate")

### 8.5 Virtueller Meetup-Raum verknüpfen

Wenn ein Meetup online stattfindet:

1. Im Meetup-Beitrag das Feld **„Jitsi-Raum"** ausfüllen (z. B. `copai-monthly-2026-06`).
2. Beim Aufruf des Meetups erhalten registrierte Teilnehmer:innen einen Button **„Konferenz beitreten"**, der zu `https://${JITSI_HOST}/copai-monthly-2026-06` führt.

Innerhalb einer BuddyPress-Gruppe lässt sich zudem über das Plugin **CoPAI BuddyPress Jitsi Sparring** ein dauerhafter Sparring-Raum aktivieren — alle Gruppenmitglieder treten ohne weitere Registrierung bei.

---

## 9. Single Sign-On WordPress → Moodle

Die Plattform realisiert Single Sign-On über OAuth2: WordPress ist der **Identitätsprovider**, Moodle der **Client**.

**Ablauf (für Nutzer:innen):**

1. Login-Seite von Moodle aufrufen.
2. Auf den Button **„CoPAI Community"** klicken.
3. Falls noch nicht in WordPress angemeldet: WordPress-Login erscheint.
4. Nach erfolgreicher Anmeldung wird die Nutzer:in zurück zu Moodle geleitet und ist eingeloggt.

**Ablauf (technisch):**

- Beim ersten Container-Start legt das Skript `moodle/setup_oauth2.php` den OAuth2-Issuer „CoPAI Community" in Moodle an. Endpunkte, Felder­zuordnung (E-Mail, Vorname, Nachname, Benutzername) und Client-ID/-Secret werden idempotent gesetzt.
- Das WordPress-Plugin **CoPAI OAuth2 Server** stellt die Endpunkte unter `/wp-json/copai-oauth/v1/*` bereit.
- Ein gemeinsames Secret (`OAUTH_CLIENT_SECRET` in der `.env`) sichert die Token-Anforderungen.

**Wichtig:** Wer keinen WordPress-Account hat, kann sich auch nicht in Moodle einloggen. Eine Self-Service-Registrierung in Moodle ist deaktiviert. Alle Nutzer­konten werden in WordPress angelegt (Mitgliedschaft).

---

## 10. Wartung, Updates & Backups

### 10.1 Plattform stoppen / starten

```bash
docker compose stop          # Alle Container anhalten
docker compose start         # Wieder starten
docker compose restart       # Neu starten
docker compose down          # Container entfernen (Daten bleiben unter ./data/)
```

### 10.2 WordPress / BuddyPress / eigene Plugins aktualisieren

```bash
# 1. Version in .env anpassen:
#    WP_VERSION=6.10
#    BUDDYPRESS_VERSION=14.1.0
#    JITSI_SPARRING_REF=v1.3.0

# 2. Neu bauen und starten:
docker compose build --no-cache wp-php wp-nginx wp-cron
docker compose up -d
```

WordPress-Plugins, die **innerhalb** des WP-Admin installiert wurden (z. B. zusätzliche Theme- oder Sprach-Plugins), aktualisieren sich über das WP-Admin-Interface wie gewohnt.

### 10.3 Moodle aktualisieren

```bash
# 1. .env: MOODLE_GIT_BRANCH=MOODLE_405_STABLE
# 2. Rebuild + Start:
docker compose build --no-cache moodle-php moodle-nginx moodle-cron
docker compose up -d

# 3. Datenbankschema migrieren:
docker compose exec moodle-php php admin/cli/upgrade.php --non-interactive
```

### 10.4 Backups

Alle persistenten Daten liegen unter `data/`. Ein vollständiges Backup erstellt:

```bash
tar czf copai-backup-$(date +%Y%m%d).tar.gz data/ traefik/acme*.json .env
```

Empfehlung: **täglich automatisiert** (z. B. via Cron), Backup-Archive auf separatem Speicher ablegen (S3, externer Server, Backup-NAS).

**Wiederherstellen:**

```bash
docker compose down
rm -rf data/ traefik/acme*.json
tar xzf copai-backup-2026-05-20.tar.gz
docker compose up -d
```

### 10.5 Komplett-Reset (alle Daten löschen)

```bash
docker compose down
rm -rf data/
mkdir -p data/wp/{content,db} data/moodle/{moodledata,db} data/jitsi/{prosody-config,prosody-plugins,jicofo,jvb,web,transcripts}
docker compose up -d
```

> **Wichtig:** Beide Unterordner pro App (`content`/`moodledata` *und* `db`) müssen leer sein, sonst überspringt der Installer den Erststart.

---

## 11. Fehlersuche (Troubleshooting)

### Browser zeigt „Verbindung unsicher" / Zertifikatswarnung

Sie verwenden den Staging-Resolver von Let's Encrypt. Das ist für lokale Tests korrekt — die Warnung kann ignoriert werden („Erweitert → Trotzdem fortfahren").

Für Produktion: `.env` → `TRAEFIK_TLS_RESOLVER=letsencrypt` und Container neu starten.

### Hostname `community.localtest.me` lädt nicht (macOS)

Manche macOS-Versionen lösen `localtest.me` nicht zuverlässig auf. Ergänzen Sie `/etc/hosts`:

```bash
sudo bash -c 'cat >> /etc/hosts <<EOF
127.0.0.1 community.localtest.me
127.0.0.1 lms.localtest.me
127.0.0.1 meet.localtest.me
127.0.0.1 traefik.localtest.me
EOF'
```

### Login bei Moodle funktioniert, aber kein Button „CoPAI Community"

Der OAuth2-Issuer ist noch nicht eingerichtet. Beim Container-Start läuft das Skript `setup_oauth2.php` automatisch — prüfen Sie die Logs:

```bash
docker compose logs moodle-php | grep -i oauth
```

Notfalls manuell starten:

```bash
docker compose exec moodle-php php /var/www/html/setup_oauth2.php
```

### Jitsi: Konferenz lädt, aber kein Audio/Video

Die UDP-Verbindung zum Videobridge funktioniert nicht. Prüfen:

1. `JITSI_JVB_ADVERTISE_IPS` ist auf die richtige IP gesetzt (siehe Abschnitt 5.6).
2. UDP-Port 10000 ist auf dem Host und im Router/Firewall geöffnet.
3. Test: `nc -u -v <SERVER-IP> 10000`

### E-Mails kommen nicht an

Im WordPress-Admin gibt es ein Plugin namens „WP Mail Log" oder einfacher per Konsole:

```bash
docker compose exec wp-php wp --allow-root --path=/var/www/html eval 'wp_mail("test@example.com","Test","Hallo");'
docker compose logs wp-php | tail
```

Häufige Ursachen: falsches Passwort, falscher Port (587 für STARTTLS, 465 für SSL), Firewall blockiert ausgehend.

### „This site can't be reached" beim ersten Aufruf

Der erste Build dauert lange. Mit folgendem Befehl prüfen, ob alle Container schon laufen:

```bash
docker compose ps
docker compose logs -f wp-php moodle-php
```

Wenn `wp-php` noch nicht `Ready` meldet, abwarten. Der erste Start kann **15 Minuten** dauern.

### Plugin-Versionen wurden geändert, sind aber im WP nicht aktiv

Die Plugin-Quellen liegen im Docker-Image, nicht im Bind-Mount. Nach Versions-Änderungen:

```bash
docker compose build --no-cache wp-php wp-cron
docker compose up -d
```

### Datenbankfehler („Error establishing a database connection")

Container `wp-db` oder `moodle-db` läuft nicht oder Passwort stimmt nicht:

```bash
docker compose ps                          # Status prüfen
docker compose logs wp-db moodle-db | tail
```

Bei dauerhaftem Problem: DB-Verzeichnis löschen (Achtung — Datenverlust!) und neu starten.

---

## 12. Architekturüberblick

### 12.1 Container & Netzwerke

| Container | Image / Build | Netzwerk(e) | Zweck |
|---|---|---|---|
| `copai-traefik` | Build (`traefik/`) | `proxy` | Reverse-Proxy + Let's Encrypt |
| `copai-wp-php` | Build (`wordpress/`) | `wp-net` | PHP-FPM für WordPress |
| `copai-wp-nginx` | Build (`wordpress/Dockerfile.nginx`) | `wp-net`, `proxy` | Webserver für WordPress |
| `copai-wp-cron` | Build (`wordpress/`) | `wp-net` | Minütlicher `wp cron` |
| `copai-wp-db` | `mariadb:10.11` | `wp-net` | WordPress-Datenbank |
| `copai-moodle-php` | Build (`moodle/`) | `moodle-net`, `proxy` | PHP-FPM für Moodle |
| `copai-moodle-nginx` | Build (`moodle/Dockerfile.nginx`) | `moodle-net`, `proxy` | Webserver für Moodle |
| `copai-moodle-cron` | Build (`moodle/`) | `moodle-net` | Minütlicher Moodle-Cron |
| `copai-moodle-db` | `mariadb:10.11` | `moodle-net` | Moodle-Datenbank |
| `copai-jitsi-web` | `jitsi/web:stable` | `jitsi-net`, `proxy` | Jitsi-Frontend |
| `copai-jitsi-prosody` | `jitsi/prosody:stable` | `jitsi-net` | XMPP-Server (intern) |
| `copai-jitsi-jicofo` | `jitsi/jicofo:stable` | `jitsi-net` | Konferenz-Fokus |
| `copai-jitsi-jvb` | `jitsi/jvb:stable` | `jitsi-net` | Videobridge (UDP 10000) |

**Isolation:** Datenbanken hängen nicht am `proxy`-Netz und sind aus dem Internet nicht erreichbar.

### 12.2 Verzeichnisstruktur des Repositories

```
copai_wp_plattform/
├── HANDBUCH.md            ← dieses Dokument
├── README.md              ← Kurzanleitung (englisch/deutsch gemischt)
├── LICENSE                ← MIT-Lizenz
├── compose.yml            ← Docker-Compose-Definition
├── env.example            ← Vorlage für die Konfiguration
├── .env                   ← reale Konfiguration (nicht im Git)
│
├── traefik/               ← Reverse-Proxy
│   ├── Dockerfile
│   ├── entrypoint.sh
│   ├── traefik.yml.tmpl
│   └── dynamic/dashboard.yml.tmpl
│
├── wordpress/             ← WordPress-Container
│   ├── Dockerfile         ← Image inkl. BuddyPress + eigene Plugins
│   ├── Dockerfile.nginx
│   ├── docker-entrypoint.sh
│   ├── nginx.conf
│   ├── mu-plugins/        ← Must-Use-Plugins (SMTP, FS-Direct)
│   └── plugins/           ← Eigene CoPAI-Plugins (Meetup-Block, OAuth2-Server)
│
├── moodle/                ← Moodle-Container
│   ├── Dockerfile         ← Moodle aus Git geklont
│   ├── Dockerfile.nginx
│   ├── docker-entrypoint.sh
│   ├── nginx.conf
│   ├── config.php
│   ├── setup_oauth2.php   ← idempotente SSO-Einrichtung
│   └── setup_smtp.php     ← idempotente SMTP-Einrichtung
│
├── data/                  ← persistente Daten (nicht im Git)
│   ├── wp/{content,db}/
│   ├── moodle/{moodledata,db}/
│   └── jitsi/{…}/
│
└── docs/superpowers/      ← Entwurfs- und Planungsdokumente
```

### 12.3 Sicherheits-Maßnahmen

- **TLS überall**: HTTP-Requests werden auf HTTPS umgeleitet (Traefik).
- **Auto-Zertifikate**: Let's Encrypt mit TLS-Challenge.
- **Datenbank-Isolation**: keine externen Ports auf MariaDB-Container.
- **Geheimnisse in `.env`**: niemals in Git committen (`.env` ist im `.gitignore`).
- **Pre-konfigurierte Passwörter**: müssen **vor Produktiv-Einsatz** geändert werden — alle Stellen sind in der `env.example` mit `change-me` / `changeme` markiert.

---

## 13. Lizenz & Förderhinweis

### Lizenz

Der gesamte selbst entwickelte Quellcode (Docker-Konfiguration, eigene WordPress-Plugins, Setup-Skripte) steht unter der **MIT-Lizenz** — siehe [`LICENSE`](LICENSE).

Genutzte Drittsoftware behält ihre jeweilige Lizenz:

| Software | Lizenz |
|---|---|
| WordPress | GPL v2 |
| BuddyPress | GPL v2 |
| Moodle | GPL v3 |
| Jitsi Meet | Apache 2.0 |
| Traefik | MIT |
| MariaDB | GPL v2 |
| Docker, Docker Compose | Apache 2.0 |

### Förderhinweis

Dieses Projekt wurde im Rahmen des Erasmus+-Programms der Europäischen Union gefördert:

- **Projekt:** Community of Practice AI
- **Projekt-Nr.:** KA210-VET-4603C73C
- **Träger:** Murbit GmbH

> **Disclaimer:** Funded by the European Union. Views and opinions expressed are however those of the author(s) only and do not necessarily reflect those of the European Union or the European Education and Culture Executive Agency (EACEA). Neither the European Union nor EACEA can be held responsible for them.

<img src="https://www.copai.community/wp-content/uploads/2024/04/DE_Co-fundedbytheEU_RGB_POS-1024x225.png" alt="Co-funded by the European Union" width="200">

### Kontakt

- Web: <https://copai.community>
- Code-Repository: siehe `README.md`
- Maintainer: Murbit GmbH

---

*Stand des Dokuments: Mai 2026. Bei Aktualisierungen der Plattform wird dieses Handbuch nachgeführt.*
