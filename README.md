# oscalendar

Laravel backend for parser sync, Telegram bot state, and private calendar feeds.

## Internal API service token

Create a token for the parser VM:

```bash
php artisan internal-token:create parser-vm --ability=sync-result
```

The command prints the plain token once. Store it on the parser VM. The database stores only `token_hash` in `internal_api_tokens`.

Every internal request must include:

```http
Authorization: Bearer <plain-token>
Content-Type: application/json
Accept: application/json
```

## Parser endpoints

All parser endpoints require the internal Bearer token.

Recommended parser VM flow:

1. Supervisor calls `POST /api/internal/parser-jobs/claim`.
2. If `job` is not `null`, supervisor starts one isolated worker process for that job.
3. Worker uses `job.login` and `job.password` to parse one user.
4. Worker may call heartbeat while parsing.
5. Worker sends parsed data to `POST /api/internal/sync-result`.
6. On parser failure before a result exists, worker calls `POST /api/internal/sync-runs/{id}/finish` with `status=failed`.

Claim one user job:

```http
POST /api/internal/parser-jobs/claim
```

Request:

```json
{
  "source": "rossiya_edu",
  "portal": "rossiya_edu",
  "locked_by": "parser-vm-1:supervisor",
  "lock_seconds": 900,
  "user_id": null
}
```

Response when a job exists:

```json
{
  "ok": true,
  "job": {
    "sync_run_id": 123,
    "user_id": 1,
    "source": "rossiya_edu",
    "portal": "rossiya_edu",
    "login": "portal-login",
    "password": "decrypted-password",
    "attempt": 1,
    "locked_by": "parser-vm-1:supervisor",
    "lock_expires_at": "2026-07-10T12:15:00+00:00"
  }
}
```

Response when no users are ready:

```json
{
  "ok": true,
  "job": null
}
```

Extend a running job lock:

```http
POST /api/internal/parser-jobs/{sync_run_id}/heartbeat
```

Request:

```json
{
  "locked_by": "parser-vm-1:worker-42",
  "lock_seconds": 900
}
```

Main result endpoint:

```http
POST /api/internal/sync-result
```

Minimal payload shape:

```json
{
  "user_id": 1,
  "source": "rossiya_edu",
  "trigger": "scheduler",
  "parsed_at": "2026-07-10T00:00:00Z",
  "roster_items": [],
  "flight_segments": []
}
```

Optional explicit sync run lifecycle:

```http
POST /api/internal/sync-runs/start
POST /api/internal/sync-runs/{id}/log
POST /api/internal/sync-runs/{id}/finish
```

If the parser starts a run explicitly, pass the returned `sync_run_id` into `/api/internal/sync-result`.

For the job API, `sync_run_id` comes from `/parser-jobs/claim`; pass it into `/sync-result`.

Finish a failed job without parsed data:

```http
POST /api/internal/sync-runs/{sync_run_id}/finish
```

```json
{
  "status": "failed",
  "error_text": "Portal login failed",
  "stats": {
    "items_found": 0,
    "segments_found": 0
  }
}
```

Append parser log entry:

```http
POST /api/internal/sync-runs/{sync_run_id}/log
```

```json
{
  "level": "error",
  "message": "Portal login failed",
  "context": {
    "step": "login"
  }
}
```

Sensitive context keys such as `password`, `token`, `authorization`, and `phones` are redacted before storage.

Parser API diagnostics are written to the default Laravel log:

```text
storage/logs/laravel.log
```

Useful log messages:

```text
Internal API authenticated
Parser job claim requested
Parser job claimed
Parser job claim returned no job
Parser job heartbeat requested
Sync result received
Sync result stored
Sync run finish requested
Sync run log requested
```

The logs include ids, statuses, counts, lock owner, and context keys. They do not include decrypted portal passwords or full parser payloads.

If a parser worker fails before sending `/sync-result`, it should call `/sync-runs/{id}/finish` with `status=failed`; otherwise the same user can be claimed again after `lock_expires_at`.

## Idempotency

`roster_items` are matched by `user_id + source + source_external_id` when `source_external_id` exists. Otherwise the backend computes and uses `source_hash`.

`flight_segments` are matched by `user_id + source + source_para_id + flight_number + starts_at` when those fields exist. Otherwise the backend computes and uses `source_hash`.

Crew and deferred/MEL rows are recreated for the matched segment inside the same DB transaction.

## Calendar and Telegram foundation

Calendar feed URL:

```http
GET /api/calendar/{long-random-token}.ics
```

Telegram bridge target:

```http
POST /api/telegram/webhook
```

## Telegram bot

The VPS bridge delivers incoming Telegram updates to Laravel. Laravel sends replies to Telegram API directly, using the bot token from `.env`.

Configure Laravel:

```env
TELEGRAM_BRIDGE_NAME=oscalendar_bot
TELEGRAM_BRIDGE_SECRET=
TELEGRAM_BOT_TOKEN=123456:telegram-token
APP_URL=https://oscalendar.ru
```

Bridge config example on the VPS:

```json
{
  "telegram_poll_timeout": 50,
  "telegram_http_timeout": 60,
  "target_http_timeout": 30,
  "bots": [
    {
      "name": "oscalendar_bot",
      "token": "123456:telegram-token-stored-only-on-vps",
      "target": "https://oscalendar.ru/api/telegram/webhook"
    }
  ]
}
```

The bridge sends Telegram updates to Laravel with:

```http
POST /api/telegram/webhook
X-TG-Bridge: vds-poller
X-TG-Bot: oscalendar_bot
```

Laravel processes the update, sends the reply through Telegram API, and returns `{"ok": true}` to the bridge.

Bot diagnostics are written to the default Laravel log:

```text
storage/logs/laravel.log
```

The log includes update ids, chat ids, Telegram user ids, commands, bridge header rejections, exceptions, and outgoing Telegram API calls. Message text is not logged because onboarding may contain portal credentials.

Create the first admin from console:

```bash
php artisan telegram:make-admin 123456789 --name="Admin"
```

User flow:

1. User sends `/start`.
2. Bot asks display name.
3. Bot asks portal login.
4. Bot asks portal password and stores it encrypted in `portal_credentials.password_encrypted`.
5. If the user was not pre-approved by admin, `users.status` remains `pending`.
6. Admin approves with `/approve TELEGRAM_ID`.

Admin commands:

```text
/pending
/approve TELEGRAM_ID
/adduser TELEGRAM_ID
```

Regular menu:

```text
Список рейсов
Ближайшее кольцо
Ближайший рейс
Мой календарь
Сменить пароль
```
