# oscalendar

Laravel backend for parser sync, Telegram bot state, and private calendar feeds.

## Подписка (ручной учёт оплат)

Администратор в личном кабинете открывает **Пользователи → Добавить оплату** и вносит подтверждённую
оплату: сумму в рублях, дату оплаты без времени и срок 30/90/180/365 дней. Можно добавить
комментарий. Начало доступа — указанная дата оплаты; при продлении — следующий день после
последнего неотменённого оплаченного периода, если он позже даты оплаты. Финальная дата —
дата начала плюс выбранное число дней, обе даты включены полностью. Например, 17.09.2026
+ 365 дней — доступ по 17.09.2027 включительно. Границы суток считаются в UTC.
API возвращает дату оплаты и даты периода как `YYYY-MM-DD`; `extension_from` — следующий
день после последней оплаченной даты. У ранее внесённых записей учитываются те же даты,
но время начала/окончания внутри дня больше не ограничивает доступ. Миграция не требуется.
Повтор запроса с тем же `request_id` не начисляет срок дважды.

По отдельной кнопке **История оплат** ошибочную запись можно отменить с причиной. История, автор и причина сохраняются;
остальные оплаченные периоды **не сдвигаются**. Отмена записи не возвращает деньги.
Полномочий «Управление пользователями» недостаточно для внесения и отмены оплат — нужен администратор.

Без подписки доступны актуальный рабочий план и подтверждение ознакомления. Подписка открывает
календарную ленту, подробности истории изменений и чтение трёх отчётов, но **не выдаёт роли**
и не расширяет область доступных строк. Импорт сохраняет отдельную проверку роли и не требует
подписки. Администратор имеет полный доступ без оплаты. Telegram-уведомления и сведения о рейсах
не изменены; команда календаря и ранее выданные ICS-ссылки проверяют действующий доступ.
Календарное приложение может сохранить старые события после окончания подписки — это его локальный кэш.

Пробного срока нет. Старым пользователям автоматически ничего не начисляется.
Закрытые отчёты/фильтры возвращают HTTP 402, календарь — 403, история возвращается без `changes`,
а `/account` не выдаёт закрытую календарную ссылку. Фронт показывает только вымышленные примеры.
Платёжный шлюз пока отсутствует: кнопка оплаты ведёт на заглушку без реквизитов и контактов.
Цены не заданы. Запись оплаты создаётся только администратором, не при посещении заглушки.

### Выкладка подписки

1. Сделайте резервную копию БД. Обновляйте бэкенд и фронт вместе в окно обслуживания.
2. После загрузки бэкенда выполните из его каталога:

   ```bash
   /opt/php/8.4/bin/php artisan migrate --force
   /opt/php/8.4/bin/php artisan route:clear
   ```

3. Соберите фронт командой `VITE_API_URL=/api npm run build -- --base=/`, загрузите содержимое
   `dist` в `public`, сохранив Laravel `index.php` и `.htaccess`. Парсеры обновлять не нужно.
4. До открытия сервиса внесите уже оплаченные периоды через административную форму.
   Иначе у всех обычных пользователей будет базовая версия, а старые ICS-ссылки перестанут обновляться.

Проверки: `php artisan test --filter=SubscriptionTest`. Тесты используют SQLite в памяти,
не обращаются к платёжным системам и не отправляют сообщения в настоящий Telegram.

## OSCalendar Monitor (отдельный технический Telegram-бот)

Работает в этом Laravel-приложении на хостинге. Новый бот получает команды через тот же
VDS polling bridge, что и пользовательский: добавляется отдельная запись в список ботов моста.
Ответы и автоматические уведомления Laravel отправляет напрямую в Telegram, как у обычного бота.
Настройки и обработчик пользовательского бота не меняются.
Он только читает состояние системы: никаких команд изменения расписаний, перезапуска VM
или выполнения shell-команд нет. Фронтенд обновлять не требуется.

### Включение на REG.RU

1. Загрузите обновлённый бэкенд и выполните из каталога проекта:

   ```bash
   /opt/php/8.4/bin/php artisan migrate --force
   /opt/php/8.4/bin/php artisan route:clear
   ```

2. Заполните `.env` на хостинге (не коммитьте токены):

   ```dotenv
   APP_URL=https://oscalendar.ru
   MONITOR_BOT_TOKEN=токен_нового_бота
   MONITOR_BRIDGE_NAME=oscalendar_monitor_bot
   MONITOR_ADMIN_IDS=ваш_числовой_Telegram_ID
   MONITOR_NODE_IDS=
   MONITOR_OFFLINE_SECONDS=300
   MONITOR_QUEUE_DELAY_MINUTES=15
   MONITOR_STALE_SYNC_MINUTES=180
   ```

   Это шаблон: замените значения. `MONITOR_ADMIN_IDS` — Telegram **user ID**, не username,
   не ID пользователя в БД и не роль «Управление пользователями». Несколько ID разделяются
   запятыми. Бот отвечает только этим людям и только в личном чате.
   При пустом `MONITOR_NODE_IDS` используются все VM, когда-либо приславшие heartbeat.
   Для явного списка укажите через запятую уникальные `LOCKED_BY_PREFIX` ваших VM.
   Такой список нужен только для обнаружения VM, ни разу не вышедшей на связь, или чтобы
   исключить выведенную из эксплуатации VM без удаления записей из БД.

   **Отдельного секрета монитора нет.** Проверка такая же, как у обычного бота:
   если существующий `TELEGRAM_BRIDGE_SECRET` задан, сверяется `X-TG-Bridge-Secret`;
   если не задан — этот заголовок не требуется. Настройки рабочего моста не меняйте.
   Переменные `MONITOR_BRIDGE_SECRET` и `MONITOR_WEBHOOK_SECRET` больше не используются,
   их можно удалить; оставшиеся значения не влияют на обработчик.

   При переходе с предыдущей версии достаточно загрузить обновлённый бэкенд и выполнить
   `php artisan config:cache`. Новая миграция не нужна. При уже работающем polling повторная
   настройка Telegram также не нужна. Если мост получил 429 из-за предыдущих повторных
   отказов, дайте минутному лимиту истечь; отключать лимит или общий сервис семи ботов не нужно.

3. Примените настройки и подготовьте **нового** бота к работе через мост:

   ```bash
   /opt/php/8.4/bin/php artisan config:cache
   /opt/php/8.4/bin/php artisan monitor:setup
   ```

   Команда удаляет прямой Telegram webhook **только у технического бота**, не удаляя
   накопленные сообщения, и задаёт список команд. Polling `getUpdates` не работает, пока
   зарегистрирован webhook ([Telegram Bot API](https://core.telegram.org/bots/api#getupdates)).
   Не используйте токен пользовательского бота. Старая команда `monitor:webhook` оставлена
   как псевдоним `monitor:setup`: она больше не регистрирует прямой webhook.

   На VDS добавьте в существующий массив `bots` вторую запись (первую не заменяйте):

   ```json
   {
     "name": "oscalendar_monitor_bot",
     "token": "ТОКЕН_НОВОГО_БОТА",
     "target": "https://oscalendar.ru/api/telegram/monitor/webhook"
   }
   ```

   Имя должно совпадать с `MONITOR_BRIDGE_NAME`, токен — с `MONITOR_BOT_TOKEN` на хостинге.
   Для запросов этой записи мост должен передавать:

   ```http
   X-TG-Bridge: vds-poller
   X-TG-Bot: oscalendar_monitor_bot
   ```

   Только если у обычного бота настроен `TELEGRAM_BRIDGE_SECRET`, передаётся и проверяется
   тот же `X-TG-Bridge-Secret`. Новые заголовки для монитора добавлять не требуется.
   Совместимость без секрета не является криптографической проверкой отправителя:
   заголовки и Telegram ID можно подделать. Общий секрет, если он настроен для существующего
   моста, остаётся обязательным и у монитора. Секреты и текст сообщений в журнал отказов не пишутся.

   После изменения конфигурации перезапустите существующий сервис моста. Администраторы
   должны открыть нового бота и нажать Start (`/start`), иначе Telegram не позволит ему
   отправлять уведомления. Адрес `/api/telegram/monitor/webhook` остался прежним, но теперь
   это **приёмник сообщений моста**, а не прямой webhook Telegram. Причина отказа возвращается
   коротким JSON и записывается в Laravel как `Monitor bridge request rejected`:
   `invalid_bridge_header`, `invalid_bot_name` или `invalid_bridge_secret`.
   Команды чужих пользователей и из групп игнорируются.

4. Обновите код на **каждой** VM парсера. В её `.env`:

   ```dotenv
   MONITOR_HEARTBEAT_ENABLED=true
   LOCKED_BY_PREFIX=parser-vm-1
   PARSER_VERSION=идентификатор_релиза_или_git_SHA
   ```

   У каждой VM своё имя. Перезапустите supervisor привычным способом. Он отправляет
   heartbeat раз в 60 секунд, даже без задач. Новых токенов для VM не нужно: используется
   существующий `INTERNAL_API_TOKEN`. Сначала обновляйте бэкенд, затем включайте heartbeat.

5. Для автоматических уведомлений добавьте в планировщике хостинга запуск **каждую минуту**:

   ```bash
   cd /полный/путь/к/oscalendar && /opt/php/8.4/bin/php artisan schedule:run
   ```

   Замените путь на фактический каталог с `artisan`. Если `schedule:run` уже запускается
   каждую минуту, вторую запись добавлять не нужно. Для проверки вручную:

   ```bash
   /opt/php/8.4/bin/php artisan monitor:check
   ```

### Что показывает бот

- `/status` / **Состояние**: ответ бэкенда и БД, число доступных VM, занятые слоты воркеров,
  готовая очередь, зависшие блокировки, сбои за 15 минут, последние успешные синхронизации
  и время последнего запуска проверки уведомлений (помогает проверить работу cron).
- `/parsers` / **Парсеры**: каждая VM, время последнего сигнала, занятые/доступные слоты,
  переданная версия. При отсутствии heartbeat более 5 минут VM считается не на связи;
  её старые показатели занятости не выдаются за актуальные.
- `/queue` / **Очередь**: задачи, срок выполнения которых наступил, с активным пользователем
  и подключением портала; будущие задачи не включаются. Зависшими считаются запуски
  `running` с истёкшим сроком блокировки.
- `/errors` / **Ошибки**: последние 8 неуспешных запусков, их ID, тип задачи, ID пользователя
  и безопасная категория ошибки. Полный текст с потенциально чувствительными данными
  остаётся в серверных логах. Повторные сбои — минимум 3 неуспешных запуска одного типа
  для одного пользователя за 15 минут, не обязательно с одинаковым текстом ошибки.

Уведомления: потеря/восстановление heartbeat VM, отсутствие всех VM, очередь старше
15 минут, истёкшие блокировки, повторные сбои и отсутствие успешного обновления расписания
у активного пользователя более 180 минут. Проверка — раз в минуту, поэтому есть задержка
до следующего запуска cron. Состояние уведомления хранится отдельно для каждого получателя
в БД: неизменившаяся проблема не повторяется; неотправленное сообщение повторяется при
следующей проверке. При восстановлении приходит отдельное сообщение. Возврат heartbeat
означает восстановление связи с supervisor, не доказательство исправности портала.

Все даты выводятся в UTC. Загрузка — **слоты воркеров, не CPU/RAM**. Доступность портала
напрямую не проверяется: видны результаты синхронизаций. Если упадёт сам хостинг,
бот тоже недоступен. При недоступности VDS-моста перестанут поступать команды, но исходящие
автоматические уведомления не зависят от моста. При недоступности БД проверки не работают;
команда состояния старается сообщить об ошибке БД, если обработка webhook ещё доступна.
Пустой `MONITOR_BOT_TOKEN` отключает автоматические уведомления.

## Frontend on the same domain

Build in `oscalendar-front` (Node.js 24+):

```bash
npm ci
VITE_API_URL=/api npm run build -- --base=/
```

Upload the contents of `dist/` into Laravel's `public/`: `public/index.html` and `public/assets/`.
Keep the existing `public/index.php`, `.htaccess`, and other backend files. Upload assets before
replacing `index.html`; keep previous hashed assets during the deployment for already-open tabs.
The generated frontend files are not committed to the backend repository.

The domain document root should point to Laravel's `public/`. Keep `index.php` first in the
index-file list. The web routes serve `public/index.html` for `/` and the known cabinet pages,
including direct visits/reloads; `/api/*` remains the backend. If the build is missing, these
pages return 503 rather than the Laravel welcome page. Unknown URLs and missing assets return 404.

After deploying updated backend routes, clear any previous route cache in the project directory.
For REG.RU with PHP 8.4:

```bash
/opt/php/8.4/bin/php artisan route:clear
```

Check `/`, `/login`, and a reload on `/deviations`. An unauthenticated request to `/api/account`
with `Accept: application/json` must still return 401, not the frontend HTML.

## AirFASE deviations

Shared database, independent of the portal parser. Deploy the backend before the frontend:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
```

PhpSpreadsheet requires PHP extensions including `gd`, `zip`, `mbstring`, `dom`, `xmlreader` and `xmlwriter`; Composer checks the complete requirements. Configure PHP `upload_max_filesize` to at least `10M`, `post_max_size` above `10M`, and the web server body limit accordingly.

Administrators have access automatically. Grant ordinary users the **Просмотр отклонений** or **Импорт отклонений** role through **Пользователи → Настроить**. These roles contain the following permissions (none are enabled for the basic user role):

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

## Roles and permissions

Users can hold several roles; access is the union of their role permissions. The `roles` table
stores a role name, description and permission keys, and `role_user` stores membership.
The permission catalog remains in `config/permissions.php`: only permissions implemented in code
can be selected. Individual `users.permissions` is retained for migration/rollback history and
is no longer an authorization source. Legacy `users.role` still distinguishes the original
local admin account from portal accounts for login/calendar setup; it no longer grants web admin access.

Initial roles: **Пользователь**, **Просмотр отклонений**, **Импорт отклонений**,
**Просмотр пользователей**, **Управление пользователями**, **Администратор**.
New accounts get the basic role, but permissions are inactive until approval (`status=active`).
The profile is always available to active users. Blocked, banned and pending users have no effective permissions.

Only holders of the protected **Администратор** role can create/edit roles or assign them.
`users.view` permits listing users; `users.manage` additionally permits ordinary user status changes,
not role assignment or modification of administrators. The administrator role cannot be edited/deleted,
the default user role cannot be deleted, and removing/blocking the last active admin is rejected.
Assigned custom roles cannot be deleted until their members are reassigned. The console command
`account:make-admin` continues to create/grant a system administrator for bootstrap or recovery.
Active users with `users.view` and `users.manage` (the “Управление пользователями” role)
receive new registration requests in their linked Telegram accounts and can approve them
using the inline button or open the list via `/pending` / “Заявки на доступ”. Permissions
are checked again on approval; revoked access invalidates old buttons. Already processed,
blocked or banned requests cannot be activated by these buttons. Managers cannot activate
administrator accounts. Existing Telegram admins also retain approval access.
Other bot admin commands (`/approve`, `/adduser`) still require `telegram_accounts.is_admin`;
assigning a web role does not set this flag. No frontend rebuild or migration is required.

The migration preserves existing users' exact permission sets, without automatically giving them
new rights. Nonstandard sets become shared **Перенесённые права #…** roles that administrators can
rename or replace later. Existing account IDs, portal credentials and Telegram links are untouched.

Admin API: `GET/POST /api/admin/roles`, `PATCH/DELETE /api/admin/roles/{id}`,
`GET /api/admin/permissions` (read-only catalog), and `PATCH /api/admin/users/{id}`
with optional `status` and `role_ids`. Old `permissions`/`role` payloads are rejected.
Role writes take `{name, description, permissions}`. Prerequisites `deviations.view` and
`users.view` are automatically included when dependent permissions are selected.

Deploy backend and frontend together: back up the database, enter Laravel maintenance mode,
upload the backend, run `php artisan migrate --force` and `php artisan optimize:clear`, upload
the new frontend build into `public`, then `php artisan up`. On REG.RU invoke artisan with
`/opt/php/8.4/bin/php`. No new Composer/NPM dependencies are required. The portal parser code is unchanged.
Reload existing browser tabs so their navigation and user-management forms use the new API.

Checks: `php artisan test --filter='RoleAccessTest|AccountApiTest|DeviationApiTest'`;
frontend `tests/roles.browser.mjs` exercises the UI against mocked APIs in Vite preview.

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

Long trips use the roster's actual arrival timestamp for scheduling detail refreshes. Corrected trips from the last two months with no saved segments can receive a recovery task even after their normal refresh window ends. A segment marked `source_payload.ops_enriched=false` updates its schedule but preserves any previously stored board, stands, crew, defects and OPS details. Deploy this backend support before the corresponding parser update on every VM; no migration is required. The calendar emits one event per saved segment and omits the parent trip once its segments are available.

For non-flight events, the parser supplies `ends_at` from the route's `[до …]` marker. Date-only periods such as leave use `source_payload.all_day=true` and an exclusive `ends_at` at midnight after the last included day. The calendar emits `DTSTART;VALUE=DATE` and `DTEND;VALUE=DATE` for these periods, while timed events retain UTC timestamps. Existing roster IDs/calendar UIDs are preserved when end dates are populated. Deploy the backend before the parser and let roster/calendar feeds refresh; no migration is required.

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

For `flight_details` only, a worker may finish with `{"status":"skipped","stats":{"skip_reason":"roster_item_removed"}}` after fetching and validating a fresh monthly workplan that confirms the assignment is absent or marked removed. Empty details alone are not proof of removal. This closes the run without a failure, without recording a successful detail refresh, and without replacing stored flight segments. The recurring task completes unless a concurrent roster update requested a refresh of a restored assignment. Deploy the backend support before updating/restarting parser supervisors; no database migration is needed. Existing historical failures are unchanged.

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
6. An approver confirms the request in Telegram or the cabinet without assigning a position. Positions and flight units are assigned separately in the cabinet. Legacy administrator commands `/approve TELEGRAM_ID` and `/adduser TELEGRAM_ID` also approve without classification.

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

## September 2026: reports, acknowledgement and pilot positions

Deploy **backend → migrations → frontend**. Back up the database first. The existing parser supports acknowledgement tasks from both Telegram and the personal account; no parser update is required.

On REG.RU, from the Laravel project directory:

```bash
/opt/php/8.4/bin/php artisan migrate --force
/opt/php/8.4/bin/php artisan optimize:clear
```

Upload the new frontend `dist` contents to `public`, preserving `index.php` and `.htaccess`. No new Composer or Python dependencies are required. Reload existing browser tabs after deployment.

- `/green-zone` and `/rrj-express` have separate tables and opt-in reader/importer roles. Existing ordinary users are not granted these roles by the migration. Admin assigns them in user settings. XLS/XLSX imports use the common reader, validate all rows before writing and skip duplicate fingerprints, excluding export-period event totals. Files must have the matching report's headers; `.xlsx.xls` is detected by content. Without an occurrence ID, identical event rows cannot be distinguished; corrected rows are new records, not edits to previous imports.
- `GET /api/{deviations|green-zone|rrj-express}/metadata` supplies column definitions, numeric fields and distinct dropdown options shared by all readers of that report. Reading metadata requires the report's view+read permissions. Each table has its own permanent Laravel cache, invalidated after an import adds records to that table and rebuilt on the next request. Duplicate-only or rejected imports leave it unchanged. Deviations: event text, level, aircraft type, parameter, pilot position, flight unit. Green zone: aircraft type, pilot position, flight unit. RRJ-Express: event text, pilot position, flight unit. The existing frontend renders these options as searchable dropdowns and refreshes metadata after importing; no frontend rebuild or database migration is needed for this cache change. All filtering, ordering, grouping and pagination (maximum 500) run on the server.
- `POST /api/change-history/{id}/acknowledge` requires `history.view`, checks ownership/current status and queues the same operation as Telegram. `GET /api/change-history/pending-count` counts only pending current-period/future changes. Past UTC months and snapshots where the portal no longer requires acknowledgement become superseded. Web status refreshes every 15 seconds; sidebar count every 60 seconds and on tab focus.
- The roster identity migration allows A → B → A to create a new event without re-enabling old buttons. Hashes now include addition/deletion type; existing pending snapshots may be superseded once at the first refresh after upgrade. Downgrading the identity migration is refused if repeated historical versions exist, rather than deleting history.
- `pilot`, `unit-head`, `senior-leader` are protected positions with no additional functional permissions; they now determine report row scope (see below). Telegram only approves registrations; no position is assigned automatically. Managers can approve and separately set positions in the cabinet, but cannot assign access roles. Old Telegram classification buttons no longer modify users. Position and unit are not returned in the user's own `/account` response.
- `GET /api/admin/flight-units` requires `users.view` + `users.manage` and merges the three cached `flight_unit` lists, removing blanks and duplicates and sorting the result. It has no additional combined cache and does not query report tables when their caches are warm. Direct database changes require invalidating the affected report cache; per-table cache locks coordinate rebuilding and invalidation. No periodic job or extra dependency is needed. Assigning a unit head requires a value from this list, checked on the backend too. The existing `users.unit_number` API/storage name is retained for compatibility, but migration `2026_09_14_000004_expand_user_flight_unit` expands it to a nullable 255-character flight unit name. Existing values are preserved; no automatic position changes occur. Deploy backend/migrations before the new frontend; this feature does not change the parser.

Checks: `php artisan test --filter='AirfaseReportsTest|PilotRolesTest|RosterAcknowledgementTest|TelegramApprovalTest|ParserTaskFlowTest'`.
To include private local sample files, set `RRJ_SAMPLE` and `GREEN_SAMPLE` to their paths. Tests use a temporary SQLite database and mock Telegram; samples are not committed or imported into the live database.

## Portal profile and report row scope

Deploy backend first and run `/opt/php/8.4/bin/php artisan migrate --force`, then update/restart the parser supervisors and deploy the rebuilt frontend. No new dependencies, environment variables or jobs are required. Back up the database before migration. The next normal roster refresh populates each account's profile; existing schedules, logins, Telegram links and display names are not changed.

`portal_profiles` stores a separate profile per **OSCalendar user ID**: portal source, full name, personnel number (a string preserving leading zeroes), sync timestamp and a private small avatar. The parser downloads the avatar through its existing authenticated portal session; JPEG/PNG/WebP up to 512 KiB are stored as base64 in the profile table, not exposed as public files. `/api/account` supplies identity and photo availability, while authenticated `/api/account/photo` supplies only the caller's photo. Failed profile downloads preserve previous data; failed photo downloads preserve identity and the previous photo. A changed identity clears the old photo. Older sync snapshots cannot overwrite newer profiles. Accounts sharing portal credentials will receive the same portal identity, while keeping separate account records.

All three report APIs scope rows on the backend, before user filters, grouping, pagination and counts. Metadata dropdowns are shared across the entire report table; selecting a value with no accessible rows returns an empty result, never another user's records:

- `pilot`: either `pilot_personnel_number` + full `pilot_name`, or `captain_code` + full `captain_name` must match the profile. `captain_code` is the captain's personnel number. Both identifiers must belong to the same person slot; matching both slots does not duplicate the row. Name matching normalizes whitespace, case and ё/е, but does not guess initials or match on name/number alone.
- `unit-head`: exact assigned `users.unit_number` / report `flight_unit`, selected through the existing directory.
- `senior-leader` and administrator: all rows, still subject to existing report permissions.
- Unassigned position, missing pilot profile or missing unit: no rows, with an explanatory metadata `access_notice`. Positions are not automatically assigned by this migration.

Import permissions remain independent: importing writes to the shared report tables and does not grant visibility to all imported rows. The flight-unit directory for authorized user managers remains global so they can assign units. Indexes on personnel number, captain code and flight unit avoid scanning all report rows for common access checks.

Checks: `php vendor/bin/phpunit --filter='PortalProfileTest|ReportVisibilityTest|DeviationApiTest|AirfaseReportsTest'`.
