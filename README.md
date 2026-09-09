# oscalendar

Laravel backend for parser sync, Telegram bot state, and private calendar feeds.

## AirFASE deviations

Shared database, independent of the portal parser. Deploy the backend before the frontend:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

PhpSpreadsheet requires PHP extensions including `gd`, `zip`, `mbstring`, `dom`, `xmlreader` and `xmlwriter`; Composer checks the complete requirements. Configure PHP `upload_max_filesize` to at least `10M`, `post_max_size` above `10M`, and the web server body limit accordingly.

Administrators have access automatically. Grant ordinary users these permissions through **Пользователи → Настроить** (none are enabled by default):

- `deviations.view`: page/navigation.
- `deviations.read`: shared table, requires `deviations.view`.
- `deviations.import`: upload, requires `deviations.view` but not `deviations.read`.

Authenticated API:

- `POST /api/deviations/import`: multipart `file`, XLS/XLSX AirFASE report, up to 10 MiB. Returns `processed`, `inserted`, `duplicates`. Required headers are defined in `Deviation::COLUMNS`; event number/text/report count are inherited from group headings. Invalid rows reject the entire import. Formulas are not executed or accepted. The uploaded workbook is not archived: only normalized rows, original filename and uploader are stored.
- `GET /api/deviations`: Laravel pagination (`data`, `total`, `current_page`, `last_page`, `per_page`). `page` defaults to 1, `per_page` to 50 (maximum 500). `sort_by` defaults to `flight_date`, `sort_order` to `desc`. `group_by` orders groups on the server, then the selected sort orders rows inside each group. Groups may continue across pages.
- Filters: `date_from` / `date_to` in `YYYY-MM-DD`, plus `filters[field]` for any field in `Deviation::COLUMNS`. Text filters use literal substring matching, date and report count use equality; all conditions combine with AND. Sorting/grouping fields are allowlisted to the same columns.

Example: `/api/deviations?date_from=2025-12-01&date_to=2025-12-09&filters[event_number]=1004&group_by=aircraft_registration&sort_by=flight_date&sort_order=desc&per_page=100`.

Deduplication uses a unique SHA-256 key over the normalized event fields, excluding the report count, filename, uploader and import timestamps. Thus weekly/monthly overlap is skipped even if report totals differ. The first report count is retained as source metadata, not a live count of filtered results. The export has no unique occurrence ID: identical event rows are considered the same occurrence; rows with different actual values remain distinct. A malformed file does not modify existing records.

Run the API/import checks with `php artisan test --filter=DeviationApiTest`. Optionally set `AIRFASE_SAMPLE` to a local AirFASE export to test importing it twice against an in-memory test database; personal exports are not committed.

## Internal API service token

Create a token for the parser VM:

```bash
php artisan internal-token:create parser-vm --ability=sync-result
```

The command prints the plain token once. Store it on the parser VM. The database stores only `token_hash` in `internal_api_tokens`.

For a second VM, run this **on the Laravel host, inside the oscalendar directory**:

```bash
php artisan internal-token:create parser-vm-2
```

Put the printed token in the second VM's parser `.env` as `INTERNAL_API_TOKEN`
and set `LOCKED_BY_PREFIX=parser-vm-2` (different from the first VM). Keep the
same backend URL and parser source. Restart the second VM's supervisor after
editing its environment. Creating a new token does not replace or revoke the
first token. Tokens and `.env` files must not be committed or posted in logs.
The optional `--ability` values are currently metadata, not enforced access scopes.

Heartbeat renewal returns HTTP 409 without modifying the run or task when the
run is closed, either lease has expired, or task status/attempt/owner no longer
matches the run. The first heartbeat may transfer a supervisor claim to its
worker; later heartbeats cannot change that worker's identity. Expiry is checked
after locking the run and task in a transaction. An expired lease cannot be
revived even before another VM claims the task. This guards lease renewal, not
remote cancellation of portal requests already executing on the old VM.

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
3. Worker dispatches `roster_refresh`, `flight_details`, or `acknowledge_roster_changes` from `job.task_type`.
4. Worker may call heartbeat while parsing.
5. Worker sends incremental data to `POST /api/internal/sync-runs/{id}/partial-result`.
6. Worker closes the execution through `POST /api/internal/sync-runs/{id}/finish`.

`parser_tasks` stores recurring schedules and leases. `sync_runs.worker_id` stores the worker that executed each attempt together with timing, duration, status, error, and counters. A task returns to `scheduled` after a run; it is not duplicated for every interval.

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
  "user_id": null,
  "capabilities": ["typed_tasks_v1"]
}
```

The capability is required so an old monolithic worker cannot claim a typed task during a mixed-version deployment.

Response when a job exists:

```json
{
  "ok": true,
  "job": {
    "sync_run_id": 123,
    "task_id": 45,
    "task_type": "flight_details",
    "task_payload": {
      "roster_item_id": 78,
      "source_external_id": "1631777",
      "source_request_raw": "1631777,2026-08-08 10:00:00.000,1",
      "boards_raw": "RA-89123",
      "starts_at": "2026-08-08T10:00:00+00:00",
      "ends_at": "2026-08-08T12:00:00+00:00",
      "roster_updated_at": "2026-08-07T12:00:00+00:00"
    },
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

`roster_refresh` payload contains the current and next month as `months`. Its roster chunks create or refresh one `flight_details` task per eligible roster item.

When a roster page contains the acknowledgement button or `warning`/`plan_new` markup, Laravel stores a versioned `roster_change_event`. A new change hash always produces a new Telegram message. The message shows the complete previous task and a `Было`/`Стало` list only for fields whose values changed. The inline acknowledgement button schedules a high-priority `acknowledge_roster_changes` task; it never calls the portal from the webhook request.

The acknowledgement task is serialized only against `roster_refresh` for the same user. Flight-detail tasks remain concurrent. After the VM posts `accept=1`, it reloads the month and Laravel sends the confirmation with the current task only after the portal reports a confirmed form and removes all `warning`/`plan_new` markup.

The VM advertises `roster_acknowledgement_v1` while claiming jobs. Laravel does not give acknowledgement tasks to an older parser that only supports the original typed-task contract.

Flight detail cadence is based on the current distance to the flight:

- within 24 hours or currently in progress: 15 minutes;
- 1-3 days: 1 hour;
- 3-4 days: 3 hours;
- 4-7 days: 6 hours;
- 7+ days: 12 hours;
- during the first 24 hours after completion: 1 hour;
- later than 24 hours after completion: task becomes `completed`.

Configure scheduling and per-user parallelism:

```env
PARSER_ROSTER_INTERVAL_MINUTES=60
PARSER_RETRY_INTERVAL_MINUTES=10
PARSER_MAX_CONCURRENT_PER_USER=3
```

Run `php artisan migrate --force` after deployment to create the task, execution, and roster-change event tables.

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

If a worker lease expires, Laravel closes its execution as failed and makes the same recurring task immediately claimable again.

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

Create the first Telegram bot admin from console:

```bash
php artisan telegram:make-admin 123456789 --name="Admin"
```

The web cabinet administrator is a separate local account and does not use anyone's portal credentials:

```bash
php artisan account:make-admin oscalendar-admin --name="Administrator"
```

The command asks for the password without displaying or storing it in shell history.

An existing test user can have a separate local web login while keeping another user's portal credentials for parsing:

```bash
php artisan account:set-local-login @Infernal1248 oscalendar-vladimir
```

Run this on the Laravel server. The first argument may instead be the exact `users.id`. The command requires an unambiguous existing user, displays their ID/name for confirmation, and asks for a password of at least 12 characters twice. It does not create a user, grant admin rights, unblock the user, or change portal credentials, Telegram links, permissions, calendar links or schedule data. Existing web tokens for that user are revoked. Running it again also changes the local password.

Users with a local login authenticate only with that login, not with portal credentials. Other users retain portal login. Thus after assigning a local login to the test user, their shared portal login resolves to the remaining ordinary user. If more than one eligible user still shares a portal login, web login is rejected instead of choosing the first record. Assign the local login immediately after deploying this change to restore the shared portal user's login. No migration or parser/frontend update is needed.

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
