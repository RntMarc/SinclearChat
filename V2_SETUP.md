# SinclearChat API v2 — Setup & Client Integration

This document covers the **JWT-based API v2** for native clients (Kotlin
Multiplatform, Swift, etc.). The legacy HMAC-based v1 still works and is
used by the Next.js web app — both versions share the same database.

- Full OpenAPI spec: [`openapi-v2.yaml`](./openapi-v2.yaml)
- Plan / design notes: [`SinclearChat_PROMPT.md`](../SinclearChat_PROMPT.md)

---

## What changed in v2

| Aspect | v1 (HMAC) | v2 (JWT + OAuth2/PKCE) |
|---|---|---|
| Auth header | `X-Hub-Signature: hex(HMAC-SHA256)` | `Authorization: Bearer <jwt>` |
| User identity | Client sends `user_id` in body | Server reads `sub` claim from JWT |
| Token refresh | None (rotated shared secret) | Refresh-token family + reuse-detection |
| Login flow | App-level (Next.js only) | OAuth2 Authorization Code + PKCE |
| Real-time | Polling | Server-Sent Events (SSE) with `Last-Event-ID` resume |
| Direct chats | `chat_id = partner_id` string | Canonical `direct_chats` row with `user_a_id < user_b_id` |
| Roles | Implicit (creator = admin) | Explicit `owner` / `admin` / `member` |
| Logout-all | Manual | `POST /api/v2/auth/logout-all` + `token_version++` |

---

## 1. One-time server setup

### 1.1 Generate the RS256 key pair

The Next.js server **signs** access tokens with the private key. The PHP
backend **verifies** them with the public key.

```bash
cd SinclearChat
php scripts/generate-rs256-keypair.php
```

This writes `keys/jwt_private.pem` and `keys/jwt_public.pem` (the
directory is git-ignored) and prints both in `.env`-ready format.

### 1.2 Distribute the keys

**Next.js `.env.local`** (or Vercel env):
```
JWT_API_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
... base64 ...
-----END PRIVATE KEY-----"
JWT_API_ISSUER="https://app.sinclear.de"   # MUST match PHP exactly
JWT_API_AUDIENCE="chat-api"
JWT_ACCESS_TTL="900"
AUTH_INTERNAL_SECRET="32+ random bytes"      # MUST match PHP exactly
```

**PHP `.env`** (SinclearChat):
```
JWT_API_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----
... base64 ...
-----END PUBLIC KEY-----"
JWT_API_ISSUER="https://app.sinclear.de"   # MUST match Next.js exactly
JWT_API_AUDIENCE="chat-api"
JWT_API_ACCESS_TTL=900
JWT_API_REFRESH_TTL=2592000
AUTH_INTERNAL_SECRET="32+ random bytes"      # MUST match Next.js exactly
```

### 1.3 Run migrations

```bash
php scripts/migrate.php
```

This applies 001–005 in order and creates:
- `auth_codes`, `refresh_token_families`, `refresh_tokens`, `jti_blacklist`
- `direct_chats` (with `chk_pair_order` CHECK constraint)
- `user_profiles`, `chat_read_receipts`, `user_presence`,
  `user_devices`, `sse_events`
- Adds `role` to `ChatRoomMembers`, `avatar` to `ChatRooms`,
  `direct_chat_id` to `ChatMessages`
- 4 cleanup events (`cleanup_messages`, `cleanup_sse_events`,
  `cleanup_jti_blacklist`, `cleanup_old_read_receipts`)

### 1.4 Attachments

Attachments stay in the database exactly as in v1 — base64 payload
stored in `ChatMessages.attachment_body` (LONGTEXT). There is **no
separate upload endpoint**, **no file storage layer**, and **no
webserver `alias` configuration**. The client base64-encodes the file
and sends it inline with the message:

```json
POST /api/v2/messages
{
  "chat_type": "direct",
  "chat_id": "019e9ecf-...",
  "body": "Look at this:",
  "attachment": {
    "type": "image",
    "data": "data:image/jpeg;base64,/9j/4AAQ..."
  }
}
```

Server-enforced size cap: `MAX_ATTACHMENT_SIZE_BYTES` (default 600,000
bytes of base64 — ~440 KB binary). The client should pre-process with
the `clientProcessImage()` helper (resize to 1920×1920, JPEG q=80)
before upload to stay under the cap.

---

## 2. OAuth2 / PKCE login flow

The native client never sees a Next.js session cookie. It runs the
standard OAuth2 Authorization Code flow with PKCE (S256, mandatory).

### 2.1 Client-side: generate PKCE pair

```
code_verifier  = base64url(random 32 bytes)   # 43 chars
code_challenge = base64url(sha256(code_verifier))
state          = base64url(random 16 bytes)   # CSRF token
```

### 2.2 Open the authorization URL

```
GET https://app.sinclear.de/api/auth/v2/authorize
    ?response_type=code
    &client_id=sinclear-beyond-mobile
    &redirect_uri=sinclearchat://callback     # MUST be allowlisted server-side
    &code_challenge=<code_challenge>
    &code_challenge_method=S256
    &state=<state>
```

If the user is already logged in on the web, the server immediately
redirects back with `?code=...&state=...`. If not, it shows a login
page (OTP / Passkey / Discord) and completes the flow on success.

The `redirect_uri` allowlist is server-side (custom schemes only, exact
match, no `http(s)://`, no `javascript:`).

### 2.3 Exchange the code for tokens

```
POST https://chat.sinclear.de/api/v2/auth/token
Content-Type: application/json

{
  "grant_type": "authorization_code",
  "code": "<code from step 2.2>",
  "code_verifier": "<code_verifier from step 2.1>",
  "redirect_uri": "sinclearchat://callback"
}
```

Response 200:
```json
{
  "access_token": "eyJhbGciOiJSUzI1NiIs...",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "01HXYZ...base64url",
  "refresh_expires_in": 2592000,
  "user": {
    "id": "019e9ecf-...",
    "display_name": "Alice",
    "avatar": "data:image/avif;base64,...",
    "status_message": "hi"
  }
}
```

### 2.4 Refresh the access token

```
POST https://chat.sinclear.de/api/v2/auth/refresh
Content-Type: application/json

{ "refresh_token": "<refresh_token>" }
```

Response 200: same shape as 2.3 (with a **new** refresh token — old one
is invalidated server-side).

**Reuse detection:** if a refresh token is presented twice, the **entire
family** is revoked with reason `reuse_detected`. This protects against
stolen-token replay. The client must store refresh tokens securely (e.g.
Keychain on iOS, EncryptedSharedPreferences on Android).

### 2.5 Authenticated requests

```
GET https://chat.sinclear.de/api/v2/chats
Authorization: Bearer <access_token>
```

Server reads `user_id` exclusively from the `sub` claim. Requests that
include `user_id` in the body are **rejected** (or, for direct chats,
the `user_b_id` field is cross-checked with the token's `sub`).

---

## 3. Server-Sent Events (SSE)

SSE replaces polling for real-time updates. It works with `curl`, OkHttp,
Ktor, etc.

```
GET https://chat.sinclear.de/api/v2/events
Authorization: Bearer <access_token>
Accept: text/event-stream
```

Response is a `text/event-stream`:
```
id: 12345
event: message.new
data: {"chat_id":"...","message":{...}}

id: 12346
event: typing
data: {"chat_id":"...","user_id":"..."}

: heartbeat  (every 25s, no data)

```

### Resuming after disconnect

Send the `Last-Event-ID` header on reconnection (it's a 64-bit integer,
the `sse_events.id` column). The server replays any events newer than
that ID, then continues the live stream.

```
GET /api/v2/events
Last-Event-ID: 12345
```

### Heartbeat

The server sends a comment line (`: heartbeat`) every 25 seconds. The
connection is closed after 300 seconds (5 minutes) — the client should
reconnect immediately. This is by design; SSE is one-way.

### Library recommendations

- **Kotlin / KMP:** `kotlinx-coroutines` + `OkHttp` with a manual SSE
  parser (the `okhttp-sse` module exists but is JVM-only). For
  multiplatform, roll your own on top of `Ktor` `client.prepareGet`.
- **iOS:** `URLSession` `bytes(for:)` async sequence, split on `\n\n`.
- **CORS:** Allowed request headers are `Authorization`, `Last-Event-ID`,
  `Content-Type`. Allowed methods: `GET`, `POST`, `PATCH`, `DELETE`,
  `OPTIONS`. The `OPTIONS` preflight returns 204 with the allow-list.

---

## 4. Direct chats

Direct chats are now **first-class resources** identified by a UUID v7
`chat_id`, not by sorting `user_a_id`/`user_b_id` strings.

### Open or create

```
POST https://chat.sinclear.de/api/v2/direct
Authorization: Bearer <jwt>
Content-Type: application/json

{ "user_id": "019e9ecf-..." }   // the OTHER participant
```

The server canonicalizes the pair (`DirectChatPair::normalize`),
enforces `user_a_id < user_b_id` via a CHECK constraint, and returns
the same `chat_id` for both directions.

### List user's chats

```
GET /api/v2/chats
```

Returns a merged list of group chats (joined via `ChatRoomMembers`) and
direct chats, sorted by `updated_at` DESC.

### Send a direct message

```
POST /api/v2/messages
{
  "chat_type": "direct",
  "chat_id": "019e9ecf-...",   // direct_chats.id
  "body": "Hello!"
}
```

The server rejects `chat_id === sub` (can't message yourself).

---

## 5. Group roles

| Action | owner | admin | member |
|---|---|---|---|
| Read messages | ✓ | ✓ | ✓ |
| Send messages | ✓ | ✓ | ✓ |
| Rename group | ✓ | ✓ | ✗ |
| Change group avatar | ✓ | ✓ | ✗ |
| Add members | ✓ | ✓ | ✗ |
| Remove members (any) | ✓ | ✗ | ✗ |
| Remove members (admin/member role) | ✓ | ✓ | ✗ |
| Delete group | ✓ | ✗ | ✗ |
| Leave group | ✓ | ✗ | ✗ |
| Promote/demote | ✓ | ✗ | ✗ |

The `moderator` role is **not** in v2.0 (it was deemed unnecessary for
the messenger use case). Add it in v2.1 if needed.

---

## 6. Kotlin / KMP example

```kotlin
import io.ktor.client.*
import io.ktor.client.call.*
import io.ktor.client.engine.okhttp.*
import io.ktor.client.plugins.*
import io.ktor.client.request.*
import io.ktor.client.streams.*
import io.ktor.http.*
import io.ktor.utils.io.*
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.*
import kotlinx.serialization.json.*
import java.security.MessageDigest
import java.security.SecureRandom
import java.util.Base64

// --- PKCE helpers --------------------------------------------------------

fun generateCodeVerifier(): String {
    val bytes = ByteArray(32).also { SecureRandom().nextBytes(it) }
    return Base64.getUrlEncoder().withoutPadding().encodeToString(bytes)
}

fun deriveCodeChallenge(verifier: String): String {
    val sha = MessageDigest.getInstance("SHA-256").digest(verifier.toByteArray())
    return Base64.getUrlEncoder().withoutPadding().encodeToString(sha)
}

// --- Token store (use EncryptedSharedPreferences on Android) ------------

class TokenStore {
    var accessToken: String? = null
    var refreshToken: String? = null
    var accessTokenExpiresAt: Long = 0L
    var userId: String? = null
    var displayName: String? = null
}

// --- Auth client --------------------------------------------------------

class AuthClient(private val baseUrl: String) {
    suspend fun startAuth(redirectUri: String, state: String): Pair<String, String> {
        val verifier = generateCodeVerifier()
        val challenge = deriveCodeChallenge(verifier)
        val url = "$baseUrl/api/auth/v2/authorize" +
            "?response_type=code" +
            "&client_id=sinclear-beyond-mobile" +
            "&redirect_uri=${redirectUri.encodeURLParameter()}" +
            "&code_challenge=$challenge" +
            "&code_challenge_method=S256" +
            "&state=$state"
        // Open in system browser; handle redirect via deep-link
        return verifier to url
    }

    suspend fun exchangeCode(
        code: String,
        verifier: String,
        redirectUri: String,
    ): JsonObject = httpClient.post("$baseUrl/api/v2/auth/token") {
        contentType(ContentType.Application.Json)
        setBody(buildJsonObject {
            put("grant_type", "authorization_code")
            put("code", code)
            put("code_verifier", verifier)
            put("redirect_uri", redirectUri)
        })
    }.body()

    suspend fun refresh(refreshToken: String): JsonObject =
        httpClient.post("$baseUrl/api/v2/auth/refresh") {
            contentType(ContentType.Application.Json)
            setBody(buildJsonObject { put("refresh_token", refreshToken) })
        }.body()
}

// --- SSE listener -------------------------------------------------------

fun sseStream(url: String, token: String, lastEventId: Long? = null): Flow<SseEvent> = flow {
    httpClient.prepareGet(url) {
        header("Authorization", "Bearer $token")
        header("Accept", "text/event-stream")
        if (lastEventId != null) header("Last-Event-ID", lastEventId.toString())
    }.execute { response ->
        val channel: ByteReadChannel = response.bodyAsChannel()
        var eventId: Long? = null
        var eventName: String? = null
        val data = StringBuilder()
        while (!channel.isClosedForRead) {
            val line = channel.readUTF8Line() ?: break
            when {
                line.isEmpty() -> {
                    if (data.isNotEmpty()) {
                        emit(SseEvent(eventId, eventName, data.toString()))
                        data.clear()
                        eventName = null
                    }
                }
                line.startsWith(":") -> { /* heartbeat, ignore */ }
                line.startsWith("id:") -> eventId = line.removePrefix("id:").trim().toLongOrNull()
                line.startsWith("event:") -> eventName = line.removePrefix("event:").trim()
                line.startsWith("data:") -> {
                    if (data.isNotEmpty()) data.append('\n')
                    data.append(line.removePrefix("data:").trim())
                }
            }
        }
    }
}

data class SseEvent(val id: Long?, val name: String?, val data: String)
```

---

## 7. Endpoints at a glance

See [`openapi-v2.yaml`](./openapi-v2.yaml) for the full spec.

| Group | Endpoints |
|---|---|
| **Auth** | `POST /api/v2/auth/token`, `POST /api/v2/auth/refresh`, `POST /api/v2/auth/logout`, `POST /api/v2/auth/logout-all`, `GET /api/v2/auth/me` |
| **Users** | `GET /api/v2/users/me`, `PATCH /api/v2/users/me`, `GET /api/v2/users/{id}` |
| **Chats** | `GET /api/v2/chats`, `GET /api/v2/chats/{chatId}`, `DELETE /api/v2/chats/{chatId}`, `GET /api/v2/chats/{chatId}/messages`, `GET /api/v2/chats/{chatId}/members`, `POST /api/v2/chats/{chatId}/read`, `GET /api/v2/chats/unread` |
| **Direct** | `GET /api/v2/direct`, `POST /api/v2/direct`, `GET /api/v2/direct/{chatId}` |
| **Groups** | `POST /api/v2/groups`, `GET /api/v2/groups/{id}`, `PATCH /api/v2/groups/{id}`, `DELETE /api/v2/groups/{id}`, `POST /api/v2/groups/{id}/leave`, `POST /api/v2/groups/{id}/members`, `DELETE /api/v2/groups/{id}/members/{userId}` |
| **Messages** | `POST /api/v2/messages`, `GET /api/v2/messages`, `POST /api/v2/messages/read` |
| **Typing** | `POST /api/v2/typing` |
| **Presence** | `POST /api/v2/presence`, `GET /api/v2/presence/{userId}` |
| **Devices** | `GET /api/v2/devices`, `POST /api/v2/devices`, `DELETE /api/v2/devices/{id}` |
| **Events** | `GET /api/v2/events` (SSE) |

Public (no auth): `GET /api/health`, `POST /api/v2/auth/token`,
`POST /api/v2/auth/refresh`.

---

## 8. Security checklist

- [ ] `JWT_API_PRIVATE_KEY` only on the Next.js server. Never on the
      client, never in version control.
- [ ] `JWT_API_PUBLIC_KEY` on PHP only.
- [ ] `AUTH_INTERNAL_SECRET` is 32+ random bytes, identical on both
      sides. Rotate at the same time as a coordinated maintenance.
- [ ] `JWT_API_ISSUER` and `JWT_API_AUDIENCE` identical on both sides.
- [ ] All native clients use PKCE S256. Plain `S256` rejection is server
      policy (`code_challenge_method !== 'S256'` → 400).
- [ ] Refresh tokens are stored in secure storage (Keychain /
      EncryptedSharedPreferences). Never in `localStorage`.
- [ ] HTTPS only. The PHP backend should redirect `:80` to `:443`.
- [ ] Validate `Last-Event-ID` is a non-negative integer (the SSE
      endpoint does this server-side; clients should not trust arbitrary
      values).
- [ ] Pre-process attachments client-side (`clientProcessImage()`) so
      the base64 payload stays under `MAX_ATTACHMENT_SIZE_BYTES`.
- [ ] Do not include `user_id` in any v2 request body. It will be
      silently ignored (or rejected, depending on the endpoint) — the
      server reads it from the token.

---

## 9. Migration path for an existing v1 client

If you already have a v1 client that uses HMAC:

1. Generate the key pair (section 1.1).
2. Deploy with v1 + v2 running side-by-side. v1 is not affected.
3. Roll out the v2 client (e.g. via feature flag).
4. Once 100% rolled out, leave v1 in place — it costs almost nothing.
5. Do not remove v1 code paths; the Next.js web app still uses them.

---

## 10. Testing

### Local contract tests

```bash
pnpm run test:v2
```

Validates PKCE S256, JWT RS256 (Node sign ↔ public key verify), HMAC
signature parity, OAuth2 redirect-URI allowlist, and direct-chat pair
canonicalization. Does not require a running server.

### Integration tests (require a live backend)

```bash
# Start MariaDB + PHP + Next.js
# Then:
./scripts/run-v2-integration-tests.sh
```

(WIP: not yet shipped — will use Playwright against a docker-compose
stack.)

---

## 11. Troubleshooting

### "Invalid token" immediately after refresh

The refresh token was used twice (reuse-detection). The entire family is
revoked — the user must re-authenticate. This is by design.

### "issuer invalid" (PHP logs)

`JWT_API_ISSUER` in `SinclearChat/.env` differs from `iss` claim in the
token. Both must be byte-identical (no trailing slash, no http vs https
mismatch).

### SSE connection drops every 5 minutes

By design — reconnect with `Last-Event-ID: <last seen id>`.

### 413 / "attachment too large"

`MAX_ATTACHMENT_SIZE_BYTES` exceeded (default 600 KB base64 = ~440 KB
binary). Pre-process the image client-side with `clientProcessImage()`
to keep payloads small, or raise the limit in PHP `.env` AND the
webserver `client_max_body_size` (Nginx) / `LimitRequestBody` (Apache).

### Database collation errors

Ensure the database was created with `utf8mb4` and `utf8mb4_unicode_ci`:

```sql
ALTER DATABASE sinclearchat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The migration runner uses these. Manually-created databases that use
`utf8mb4_general_ci` will cause "Illegal mix of collations" errors on
joins.
