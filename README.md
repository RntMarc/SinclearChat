# SinclearChat API

Eine schlanke PHP-8-Chat-API, die mit MySQL als Speicher-Backend arbeitet.
Kommunikation erfolgt ausschließlich über HTTP/JSON; alle Endpunkte (außer
`/api/health`) sind mit HMAC-SHA256 gegen ein gemeinsames Geheimnis abgesichert.

Die API ist als reines Backend gedacht und wird typischerweise hinter einem
Next.js-Frontend auf Vercel betrieben. Sie verwaltet **keine eigene
Authentifizierung** – sie vertraut ausschließlich auf die kryptografische
Signatur der Requests, die aus dem Next.js-Projekt erzeugt wird.

> **Zwei API-Versionen parallel:** Diese Repo enthält sowohl die
> HMAC-basierte v1 (für das Next.js-Web) als auch die JWT-basierte v2
> (für native Mobile-Clients). Beide Versionen teilen die Datenbank,
> Tabellen und Cleanup-Events. Setup und Client-Integration für v2:
> **[V2_SETUP.md](./V2_SETUP.md)**. OpenAPI-Spec: **[openapi-v2.yaml](./openapi-v2.yaml)**.

## Inhaltsverzeichnis

- [Funktionsumfang](#funktionsumfang)
- [Anforderungen](#anforderungen)
- [Installation](#installation)
- [Konfiguration](#konfiguration)
- [Datenbank-Setup](#datenbank-setup)
- [Webserver-Konfiguration](#webserver-konfiguration)
- [HMAC-Authentifizierung](#hmac-authentifizierung)
- [API-Endpoints](#api-endpoints)
- [OpenAPI-Spezifikation](#openapi-spezifikation)
- [Next.js-Integration](#nextjs-integration)
- [Cron / Cleanup](#cron--cleanup)
- [Projektstruktur](#projektstruktur)
- [Sicherheits-Hinweise](#sicherheits-hinweise)
- [Troubleshooting](#troubleshooting)
- [API v2 (JWT / OAuth2 / SSE) — siehe V2_SETUP.md](./V2_SETUP.md)

---

## Funktionsumfang

- **Nachrichten senden** (`POST /api/messages`) – Direkt- und Gruppennachrichten,
  optional mit Anhang-URL.
- **Nachrichten abrufen** (`GET /api/messages`) – nach Direktpartner oder
  Chatraum, mit Paginierung über Zeitstempel.
- **Chaträume auflisten** (`GET /api/rooms`, `GET /api/rooms/{id}`) –
  inklusive konfigurierbarer Aufbewahrungsdauer (`ttl_days`).
- **Health-Check** (`GET /api/health`) – öffentlich, kein HMAC nötig.
- **Automatisches Cleanup** – per MySQL-Event täglich, abhängig von der
  pro Raum konfigurierten TTL.

---

## Anforderungen

- **PHP ≥ 8.2** (CLI und FPM) mit folgenden Extensions:
  - `pdo_mysql`
  - `mbstring`
  - `openssl`
- **MySQL ≥ 5.7** oder **MariaDB ≥ 10.3** (für `TIMESTAMP(6)` und
  Event Scheduler)
- **Composer** (für `ramsey/uuid`)
- Webserver: Nginx + PHP-FPM **oder** Apache + mod_php

---

## Installation

```bash
git clone <repository-url> sinclearchat
cd sinclearchat/chat-api

composer install --no-dev --optimize-autoloader

cp .env.example .env
chmod 600 .env   # Nur Owner darf lesen/schreiben
```

Anschließend `.env` anpassen (siehe [Konfiguration](#konfiguration)).

---

## Konfiguration

Alle Konfigurationswerte stehen in der Datei `.env` im Projekt-Root.
Format: `KEY=value`, Kommentare beginnen mit `#`, Werte dürfen in
Anführungszeichen stehen.

| Variable | Default | Beschreibung |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | MySQL-Host |
| `DB_PORT` | `3306` | MySQL-Port |
| `DB_NAME` | `sinclearchat` | Datenbank-Name |
| `DB_USER` | `root` | DB-Benutzer |
| `DB_PASSWORD` | _leer_ | DB-Passwort |
| `SHARED_SECRET` | _Pflicht_ | Gemeinsames Geheimnis für HMAC – **muss identisch** in Vercel-`.env` sein |
| `HMAC_HEADER_PREFIX` | `X-Hub` | Prefix für die HMAC-Header (`X-Hub-Timestamp`, `X-Hub-Signature`) |
| `DEFAULT_TTL_DAYS` | `30` | Aufbewahrungsdauer in Tagen für Direktnachrichten (Gruppen-Chats nutzen `chat_rooms.ttl_days`) |
| `MAX_MESSAGE_LIMIT` | `200` | Maximalwert für `limit`-Parameter bei Pull-Requests |
| `DEFAULT_MESSAGE_LIMIT` | `50` | Default-Limit, falls Client keins sendet |
| `CORS_ORIGIN` | `*` | Erlaubte Origin für CORS (z.B. `https://app.example.com`) |

> ⚠️ **`SHARED_SECRET` niemals in Git einchecken.** Die `.env` ist über
> `.gitignore` auszuschließen.

---

## Datenbank-Setup

```bash
mysql -u root -p -e "CREATE DATABASE sinclearchat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p sinclearchat < migrations/001_schema.sql
mysql -u root -p sinclearchat < migrations/002_events.sql
```

Alternativ der mitgelieferte Migration-Runner, der auch die
Default-TTL aus `.env` korrekt in das Event einsetzt:

```bash
php scripts/migrate.php
```

Der Migration-Runner:
- aktiviert den MySQL-Event-Scheduler (`SET GLOBAL event_scheduler = ON`)
- erstellt das Cleanup-Event `cleanup_messages`, das täglich alte Nachrichten
  löscht
- ersetzt den Platzhalter `{{DEFAULT_TTL_DAYS}}` in `002_events.sql` durch
  den Wert aus `.env`

### Tabellen

**`chat_rooms`** – Konfiguration pro Chatraum
| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | `VARCHAR(255)` PK | Chatraum-ID (z.B. `general`) |
| `name` | `VARCHAR(255)` | Anzeigename |
| `description` | `TEXT` | Beschreibung (nullable) |
| `ttl_days` | `INT UNSIGNED` | Aufbewahrungsdauer in Tagen |
| `created_at` / `updated_at` | `TIMESTAMP` | |

**`chat_room_members`** – Mitgliedschaften (vom Next.js-Projekt verwaltet)
| Spalte | Typ |
|---|---|
| `chat_room_id` | `VARCHAR(255)` PK, FK → `chat_rooms.id` |
| `user_id` | `VARCHAR(255)` PK |
| `joined_at` | `TIMESTAMP` |

**`messages`** – alle Chat-Nachrichten
| Spalte | Typ | Beschreibung |
|---|---|---|
| `id` | `BINARY(16)` PK | UUID v7 (16 Byte) |
| `user_id` | `VARCHAR(255)` | Absender |
| `chat_id` | `VARCHAR(255)` | Bei `direct`: Partner-ID; bei `group`: Raum-ID |
| `chat_type` | `ENUM('direct','group')` | Nachrichten-Typ |
| `body` | `TEXT` | Nachrichtentext |
| `attachment_url` | `VARCHAR(2048)` | Optionaler Anhang |
| `created_at` | `TIMESTAMP(6)` | Mikrosekunden-genauer Zeitstempel |

Indizes auf `(chat_type, chat_id, created_at)`, `(user_id, chat_type, chat_id)`
und `(created_at)` ermöglichen effiziente Paginierung.

---

## Webserver-Konfiguration

### Nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name chat-api.example.com;

    root /var/www/sinclearchat/chat-api/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/chat-api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/chat-api.example.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_read_timeout 30;
    }

    location ~ /\.ht { deny all; }
}
```

> **Wichtig:** Document-Root ist `public/`, damit `vendor/`, `.env` und
> `migrations/` niemals über HTTP erreichbar sind.

### Apache

In `public/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

---

## HMAC-Authentifizierung

Jeder Request (außer `/api/health`) muss zwei zusätzliche Header senden:

| Header | Wert |
|---|---|
| `X-Hub-Timestamp` | Unix-Timestamp in Sekunden (max. 5 Minuten alt) |
| `X-Hub-Signature` | `hex(HMAC-SHA256(secret, payload))` |

**Payload-Format:**

```
{timestamp}.{HTTP_METHOD}.{URI_PATH}.{REQUEST_BODY}
```

- `HTTP_METHOD` wird in **Großbuchstaben** übergeben
- `URI_PATH` ist der Pfad ohne Query-String, beginnend mit `/`
- `REQUEST_BODY` ist bei GET-Requests ein leerer String, bei POST/DELETE
  der rohe Request-Body (typischerweise JSON)

**Beispiel (Node.js / TypeScript, Next.js):**

```ts
import crypto from 'crypto';

async function callChatApi(method: string, path: string, body: string = '') {
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const payload = `${timestamp}.${method.toUpperCase()}.${path}.${body}`;
  const signature = crypto
    .createHmac('sha256', process.env.SINCLEARCHAT_SHARED_SECRET!)
    .update(payload)
    .digest('hex');

  const res = await fetch(`https://chat-api.example.com${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-Hub-Timestamp': timestamp,
      'X-Hub-Signature': signature,
    },
    body: method === 'GET' ? undefined : body,
  });

  return res.json();
}
```

**Vercel `.env` (Next.js):**

```
SINCLEARCHAT_API_URL=https://chat-api.example.com
SINCLEARCHAT_SHARED_SECRET=<identisch mit PHP-Server .env>
```

---

## API-Endpoints

### `GET /api/health`

Öffentlich, ohne HMAC. Für Monitoring/Loadbalancer.

```json
{ "status": "ok", "timestamp": "2026-05-30T12:00:00Z" }
```

### `POST /api/messages`

Sendet eine Nachricht. Server vergibt UUID v7 und Zeitstempel.

```json
{
  "user_id": "auth0|abc123",
  "chat_id": "auth0|def456",
  "chat_type": "direct",
  "body": "Hallo!",
  "attachment_url": null
}
```

Antwort 201:

```json
{
  "message": {
    "id": "018f8c9a7b3c7a8e9f0a1b2c3d4e5f60",
    "user_id": "auth0|abc123",
    "chat_id": "auth0|def456",
    "chat_type": "direct",
    "body": "Hallo!",
    "attachment_url": null,
    "created_at": "2026-05-30 12:00:00.123456"
  }
}
```

### `GET /api/messages`

**Direktnachrichten** (`chat_type=direct`):

```
GET /api/messages?chat_type=direct
   &user_id=auth0|abc123
   &chat_partner_id=auth0|def456
   &after=2026-05-30T11:00:00Z    (optional, Polling)
   &before=2026-05-30T13:00:00Z   (optional, Paginierung rückwärts)
   &limit=50
```

**Gruppennachrichten** (`chat_type=group`):

```
GET /api/messages?chat_type=group
   &user_id=auth0|abc123
   &chat_id=general
   &before=2026-05-30T13:00:00Z
   &limit=50
```

Antwort 200:

```json
{
  "data": [ /* Messages, neuste zuerst */ ],
  "pagination": {
    "has_more": true,
    "next_before": "2026-05-30 11:30:00.000000"
  }
}
```

`pagination.next_before` einfach als `before`-Parameter der nächsten
Anfrage verwenden, um rückwärts zu paginieren. Für Polling den `after`-
Parameter auf den letzten gesehenen `created_at`-Wert setzen.

### `GET /api/rooms`

Liefert alle verfügbaren Chaträume inklusive TTL.

```json
{
  "data": [
    {
      "id": "general",
      "name": "Allgemein",
      "description": "Offener Chat",
      "ttl_days": 30,
      "created_at": "2026-01-01 00:00:00",
      "updated_at": "2026-01-01 00:00:00"
    }
  ]
}
```

### `GET /api/rooms/{id}`

Liefert einen einzelnen Chatraum.

---

## OpenAPI-Spezifikation

Die vollständige API-Spezifikation liegt in [`openapi.yaml`](./openapi.yaml)
im OpenAPI-3.0-Format. Sie kann direkt in folgende Tools geladen werden:

- [Swagger Editor](https://editor.swagger.io/)
- [Redocly](https://redocly.com/)
- Postman (Import → OpenAPI)

Aus der Spezifikation lässt sich auch automatisch ein TypeScript-Client
für Next.js generieren, z.B. mit `openapi-typescript-codegen` oder
`orval`.

---

## Next.js-Integration

Vereinfachtes Beispiel mit React-Hook-artigem Polling:

```ts
// lib/chat.ts
export async function pushMessage(input: {
  userId: string;
  chatId: string;
  chatType: 'direct' | 'group';
  body: string;
  attachmentUrl?: string | null;
}) {
  const body = JSON.stringify({
    user_id: input.userId,
    chat_id: input.chatId,
    chat_type: input.chatType,
    body: input.body,
    attachment_url: input.attachmentUrl ?? null,
  });
  return callChatApi('POST', '/api/messages', body);
}

export async function pullMessages(input: {
  userId: string;
  chatId: string;
  chatType: 'direct' | 'group';
  chatPartnerId?: string;
  after?: string;
  before?: string;
  limit?: number;
}) {
  const params = new URLSearchParams({
    chat_type: input.chatType,
    user_id: input.userId,
    ...(input.chatPartnerId && { chat_partner_id: input.chatPartnerId }),
    ...(input.chatId && { chat_id: input.chatId }),
    ...(input.after && { after: input.after }),
    ...(input.before && { before: input.before }),
    ...(input.limit && { limit: String(input.limit) }),
  });
  return callChatApi('GET', `/api/messages?${params}`);
}
```

**Polling (empfohlen: alle 2–5 Sekunden):**

```ts
useEffect(() => {
  const interval = setInterval(async () => {
    const res = await pullMessages({ userId, chatId, chatType, after: lastSeen });
    if (res.data.length) setMessages(prev => [...res.data.reverse(), ...prev]);
  }, 3000);
  return () => clearInterval(interval);
}, [userId, chatId, lastSeen]);
```

**Scrolling (ältere Nachrichten laden):**

```ts
const onScrollTop = async () => {
  const res = await pullMessages({ ..., before: oldestMessage.created_at, limit: 50 });
  if (res.data.length) {
    setMessages(prev => [...res.data.reverse(), ...prev]);
    setHasMore(res.pagination.has_more);
  }
};
```

---

## Cron / Cleanup

Der Cleanup-Job wird durch das MySQL-Event `cleanup_messages` ausgeführt
und muss **nicht** extern gecront werden. Das Event läuft einmal täglich
und löscht:

- alle Direktnachrichten, die älter als `DEFAULT_TTL_DAYS` (aus `.env`) sind
- alle Gruppennachrichten, die älter als die `ttl_days` des jeweiligen
  Chatraums sind

**Event-Status prüfen:**

```sql
SHOW EVENTS;
SELECT * FROM information_schema.EVENTS WHERE EVENT_NAME = 'cleanup_messages';
```

Falls der Event-Scheduler global deaktiviert ist (z.B. in Managed-MySQL-
Umgebungen), muss er in der `my.cnf` aktiviert werden:

```ini
[mysqld]
event_scheduler = ON
```

Alternativ kann der Cleanup auch manuell ausgeführt werden:

```sql
DELETE m
FROM messages m
LEFT JOIN chat_rooms r ON m.chat_id = r.id AND m.chat_type = 'group'
WHERE
    (m.chat_type = 'group' AND m.created_at < NOW() - INTERVAL COALESCE(r.ttl_days, 30) DAY)
    OR (m.chat_type = 'direct' AND m.created_at < NOW() - INTERVAL 30 DAY);
```

---

## Projektstruktur

```
chat-api/
├── public/
│   └── index.php              Front Controller (alle Requests landen hier)
├── src/
│   ├── Config.php             .env-Loader
│   ├── Database.php           PDO-MySQL (Single + Migration-Connection)
│   ├── Auth.php               HMAC-SHA256-Verifikation
│   ├── Router.php             Eigenes URL-Routing
│   ├── Response.php           JSON-Response-Helfer
│   ├── Controllers/
│   │   ├── MessageController.php
│   │   └── RoomController.php
│   ├── Models/
│   │   ├── Message.php
│   │   └── Room.php
│   └── Helper/
│       └── UuidV7.php         UUID-v7-Generator (zeitbasiert, sortierbar)
├── migrations/
│   ├── 001_schema.sql         Tabellen
│   └── 002_events.sql         MySQL-Event für Cleanup
├── scripts/
│   ├── migrate.php            Migration-Runner
│   └── serve.sh               Dev-Server (php -S)
├── openapi.yaml               OpenAPI-3.0-Spezifikation
├── composer.json
├── .env.example
└── .env
```

---

## Sicherheits-Hinweise

1. **`SHARED_SECRET` ist ein langlebiges Geheimnis.** Bei Verdacht auf
   Kompromittierung **sofort** in PHP und Vercel austauschen.
2. **HTTPS erzwingen** – ohne TLS ist die HMAC-Signatur wertlos, da ein
   Angreifer sonst sowohl den Body als auch die Header mitlesen kann.
3. **Timestamp-Toleranz von 5 Minuten** schützt gegen Replay-Attacken.
   Für zusätzlichen Schutz kann ein Nonce-Tracking ergänzt werden
   (nicht enthalten).
4. **PHP-Stack aktuell halten** – mind. PHP 8.2 für aktive Security-Support.
5. **`.env` ist schreibgeschützt** zu setzen (`chmod 600`), da es das
   DB-Passwort und das HMAC-Secret enthält.
6. **Datenbank-Benutzer** sollte nur `SELECT`, `INSERT`, `DELETE` auf
   den relevanten Tabellen haben – **kein** `DROP`/`ALTER` zur Laufzeit.
7. **Logging:** In Produktion sollte ein `error.log` außerhalb des
   Document-Roots liegen und der `display_errors`-Modus aus sein.

---

## Troubleshooting

### „Invalid or missing HMAC signature"

- Stimmt das `SHARED_SECRET` zwischen PHP-`.env` und Vercel-`.env` exakt
  überein? (Keine Leerzeichen / Zeilenumbrüche.)
- Wird der Timestamp in **Sekunden** und als String übergeben?
- Ist die URI in der Payload exakt der Pfad **ohne** Query-String?
- Wird der **rohe** Body für die Signatur verwendet (kein re-formatted JSON)?

### „Failed to fetch messages: PDOException"

- Sind `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` korrekt?
- Existiert die Datenbank und wurden die Migrationen ausgeführt?
- Hat der DB-Benutzer ausreichend Rechte?

### Event läuft nicht

```sql
SHOW VARIABLES LIKE 'event_scheduler';
-- Falls OFF:
SET GLOBAL event_scheduler = ON;
```

In `my.cnf` dauerhaft setzen.

### CORS-Fehler im Browser

`Access-Control-Allow-Origin` in den Response-Headers prüfen. Bei
`CORS_ORIGIN=*` und Credentials-Requests (`withCredentials=true`) muss
eine konkrete Origin gesetzt werden.

### `migrate.php` schlägt mit „DELIMITER" fehl

Sicherstellen, dass die `migrations/002_events.sql` keine
`DELIMITER`-Direktive mehr enthält (wurde im aktuellen Stand entfernt).
Falls eine ältere Version der Datei vorliegt, diese bitte aktualisieren.

---

## Lizenz

Privates Projekt.
