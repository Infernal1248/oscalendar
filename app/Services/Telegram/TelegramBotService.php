<?php

namespace App\Services\Telegram;

use App\Models\CalendarFeed;
use App\Models\FlightDeferredItem;
use App\Models\FlightSegment;
use App\Models\PortalCredential;
use App\Models\RosterItem;
use App\Models\RosterChangeEvent;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\ParserTaskScheduler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramBotService
{
    private const STATE_DISPLAY_NAME = 'onboarding.display_name';
    private const STATE_PORTAL_LOGIN = 'onboarding.portal_login';
    private const STATE_PORTAL_PASSWORD = 'onboarding.portal_password';
    private const STATE_CHANGE_PASSWORD = 'portal.change_password';

    private $client;

    public function __construct(
        TelegramBotClient $client,
        private ParserTaskScheduler $taskScheduler,
        private RosterChangeNotifier $rosterChangeNotifier
    )
    {
        $this->client = $client;
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            Log::info('Telegram bot handling callback', [
                'telegram_id' => $update['callback_query']['from']['id'] ?? null,
                'chat_id' => $update['callback_query']['message']['chat']['id'] ?? null,
                'callback_data' => $update['callback_query']['data'] ?? null,
            ]);

            $this->handleCallback($update['callback_query']);
            return;
        }

        if (! isset($update['message'])) {
            Log::info('Telegram bot ignored update without message or callback', [
                'update_keys' => array_keys($update),
            ]);

            return;
        }

        $message = $update['message'];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $from = $message['from'] ?? [];
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === 0 || empty($from['id'])) {
            Log::warning('Telegram bot ignored message without chat or sender', [
                'chat_id' => $chatId ?: null,
                'has_from_id' => ! empty($from['id']),
            ]);

            return;
        }

        $account = $this->findOrRegisterTelegramAccount($from);
        $this->syncTelegramProfile($account, $from);

        if ($text === '/start') {
            Log::info('Telegram bot command received', [
                'command' => '/start',
                'chat_id' => $chatId,
                'telegram_id' => $from['id'],
                'user_id' => $account->user_id,
            ]);

            $this->handleStart($chatId, $account);
            return;
        }

        if ($text === '/help') {
            Log::info('Telegram bot command received', [
                'command' => '/help',
                'chat_id' => $chatId,
                'telegram_id' => $from['id'],
                'user_id' => $account->user_id,
            ]);

            $this->sendHelp($chatId, $account);
            return;
        }

        if ($this->handleConversation($chatId, $account, $text)) {
            return;
        }

        if ($this->isAdmin($account) && $this->handleAdminCommand($chatId, $text)) {
            return;
        }

        if (! $this->isActiveUser($account)) {
            Log::info('Telegram bot user is pending approval', [
                'chat_id' => $chatId,
                'telegram_id' => $account->telegram_id,
                'user_id' => $account->user_id,
            ]);

            $this->sendPendingMessage($chatId, $account);
            return;
        }

        $this->handleUserText($chatId, $account, $text);
    }

    private function handleStart(int $chatId, TelegramAccount $account): void
    {
        if (! $account->user->display_name) {
            Log::info('Telegram onboarding asks display name', [
                'telegram_id' => $account->telegram_id,
                'user_id' => $account->user_id,
            ]);

            $this->setConversation($account, self::STATE_DISPLAY_NAME);
            $this->client->sendMessage($chatId, "Добро пожаловать в oscalendar.\n\nКак к вам обращаться?");
            return;
        }

        if (! $this->hasPortalCredentials($account->user)) {
            Log::info('Telegram onboarding asks portal login', [
                'telegram_id' => $account->telegram_id,
                'user_id' => $account->user_id,
            ]);

            $this->setConversation($account, self::STATE_PORTAL_LOGIN);
            $this->client->sendMessage($chatId, "Отлично, {$this->e($account->user->display_name)}.\n\nВведите логин от личного кабинета.");
            return;
        }

        if (! $this->isActiveUser($account)) {
            Log::info('Telegram start from pending user', [
                'telegram_id' => $account->telegram_id,
                'user_id' => $account->user_id,
            ]);

            $this->sendPendingMessage($chatId, $account);
            return;
        }

        $this->client->sendMessage($chatId, "{$this->e($account->user->display_name)}, добро пожаловать в систему.", [
            'reply_markup' => $this->mainKeyboard($account),
        ]);
    }

    private function handleConversation(int $chatId, TelegramAccount $account, string $text): bool
    {
        $conversation = $account->activeConversation();

        if (! $conversation) {
            return false;
        }

        Log::info('Telegram bot handling conversation state', [
            'state' => $conversation->state,
            'telegram_id' => $account->telegram_id,
            'user_id' => $account->user_id,
        ]);

        if ($text === '') {
            $this->client->sendMessage($chatId, 'Пожалуйста, отправьте текстовое значение.');
            return true;
        }

        if ($conversation->state === self::STATE_DISPLAY_NAME) {
            $account->user->forceFill(['display_name' => mb_substr($text, 0, 150)])->save();
            $this->setConversation($account, self::STATE_PORTAL_LOGIN);
            $this->client->sendMessage($chatId, "Приятно познакомиться, {$this->e($text)}.\n\nТеперь введите логин от личного кабинета.");
            return true;
        }

        if ($conversation->state === self::STATE_PORTAL_LOGIN) {
            $this->setConversation($account, self::STATE_PORTAL_PASSWORD, ['login' => $text]);
            $this->client->sendMessage($chatId, "Логин принял.\n\nТеперь отправьте пароль от личного кабинета. Я сохраню его только в зашифрованном виде.");
            return true;
        }

        if ($conversation->state === self::STATE_PORTAL_PASSWORD) {
            $payload = $conversation->payload ?: [];
            $this->savePortalCredentials($account->user, $payload['login'] ?? '', $text);
            $this->closeConversation($conversation);

            if ($this->isActiveUser($account)) {
                $this->client->sendMessage($chatId, 'Готово. Доступ включён, меню открыто.', [
                    'reply_markup' => $this->mainKeyboard($account),
                ]);
                return true;
            }

            $this->notifyAdminsAboutPendingUser($account);
            $this->sendPendingMessage($chatId, $account);
            return true;
        }

        if ($conversation->state === self::STATE_CHANGE_PASSWORD) {
            $this->savePortalCredentials($account->user, $this->portalLogin($account->user), $text);
            $this->closeConversation($conversation);
            $this->client->sendMessage($chatId, 'Пароль личного кабинета обновлён.', [
                'reply_markup' => $this->mainKeyboard($account),
            ]);
            return true;
        }

        return false;
    }

    private function handleAdminCommand(int $chatId, string $text): bool
    {
        if (preg_match('/^\/(?:approve|adduser)\s+(\d+)$/u', $text, $matches)) {
            $account = TelegramAccount::query()
                ->where('telegram_id', $matches[1])
                ->with('user')
                ->first();

            if (! $account) {
                $user = User::query()->create(['status' => 'active']);
                $account = TelegramAccount::query()->create([
                    'user_id' => $user->id,
                    'telegram_id' => $matches[1],
                    'is_admin' => false,
                ]);
                $this->client->sendMessage($chatId, 'Пользователь добавлен. После /start бот соберёт имя и данные кабинета.');
                return true;
            }

            $account->user->forceFill(['status' => 'active'])->save();
            $this->client->sendMessage($chatId, "Пользователь {$account->telegram_id} активирован.");
            $this->client->sendMessage((int) $account->telegram_id, 'Доступ одобрен. Можно пользоваться меню.', [
                'reply_markup' => $this->mainKeyboard($account),
            ]);
            return true;
        }

        if ($text === '/pending' || $text === 'Заявки на доступ') {
            $this->sendPendingUsers($chatId);
            return true;
        }

        return false;
    }

    private function handleUserText(int $chatId, TelegramAccount $account, string $text): void
    {
        switch ($text) {
            case 'Список рейсов':
                $this->sendRosterList($chatId, $account);
                return;

            case 'Ближайшее кольцо':
                $this->sendNearestRosterItem($chatId, $account);
                return;

            case 'Ближайший рейс':
                $this->sendNearestFlightSegment($chatId, $account);
                return;

            case 'Сменить пароль':
                $this->setConversation($account, self::STATE_CHANGE_PASSWORD);
                $this->client->sendMessage($chatId, 'Введите новый пароль от личного кабинета.');
                return;

            case 'Мой календарь':
                $this->sendCalendarLink($chatId, $account);
                return;

            case 'Открыть меню':
            case '':
            default:
                $this->client->sendMessage($chatId, 'Меню открыто.', [
                    'reply_markup' => $this->mainKeyboard($account),
                ]);
        }
    }

    private function handleCallback(array $callback): void
    {
        $from = $callback['from'] ?? [];
        $account = $this->findOrRegisterTelegramAccount($from);
        $chatId = (int) ($callback['message']['chat']['id'] ?? $account->telegram_id);
        $data = (string) ($callback['data'] ?? '');

        if (! $this->isActiveUser($account)) {
            if (! empty($callback['id'])) {
                $this->client->answerCallbackQuery($callback['id']);
            }
            $this->sendPendingMessage($chatId, $account);
            return;
        }

        if (preg_match('/^admin\.approve:(\d+)$/', $data, $matches)) {
            if (! $this->isAdmin($account)) {
                $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Недостаточно прав.');
                return;
            }

            $pendingAccount = TelegramAccount::query()
                ->whereKey((int) $matches[1])
                ->with('user')
                ->first();
            if (! $pendingAccount || ! $pendingAccount->user) {
                $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Заявка уже обработана.');
                return;
            }

            $activated = User::query()
                ->whereKey($pendingAccount->user_id)
                ->where('status', 'pending')
                ->update(['status' => 'active']);
            if ($activated === 0) {
                $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Заявка уже обработана.');
                return;
            }

            $pendingAccount->user->refresh();
            if (! empty($callback['message']['message_id'])) {
                $this->client->removeInlineKeyboard($chatId, (int) $callback['message']['message_id']);
            }
            $personnelNumber = $this->portalLogin($pendingAccount->user);
            $this->client->answerCallbackQuery(
                (string) ($callback['id'] ?? ''),
                'Пользователь '.($personnelNumber ?: $pendingAccount->user->display_name).' одобрен.'
            );
            $this->client->sendMessage(
                (int) $pendingAccount->telegram_id,
                'Доступ одобрен. Можно пользоваться меню.',
                ['reply_markup' => $this->mainKeyboard($pendingAccount)]
            );
            return;
        }

        if (preg_match('/^roster\.ack:(\d+)$/', $data, $matches)) {
            $event = RosterChangeEvent::query()
                ->whereKey((int) $matches[1])
                ->where('user_id', $account->user_id)
                ->first();
            if (! $event || $event->status === 'superseded') {
                $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Появились более новые изменения.');
                return;
            }
            if ($event->status === 'acknowledged') {
                $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Изменения уже подтверждены.');
                return;
            }
            if ($event->status === 'acknowledgement_requested') {
                $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Подтверждение уже выполняется.');
                return;
            }

            $task = $this->taskScheduler->scheduleRosterAcknowledgement($event);
            if (! $task) {
                $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Состояние изменений уже обновилось.');
                return;
            }
            $event->refresh();
            $this->rosterChangeNotifier->removeButtons($event);
            $this->client->answerCallbackQuery((string) ($callback['id'] ?? ''), 'Подтверждение поставлено в очередь.');
            $this->client->sendMessage($chatId, 'Подтверждаем ознакомление с изменениями…');
            return;
        }

        if (! empty($callback['id'])) {
            $this->client->answerCallbackQuery($callback['id']);
        }

        if (preg_match('/^details\.flight:(\d+)$/', $data, $matches)) {
            $this->sendFlightDetails($chatId, $account, (int) $matches[1]);
            return;
        }

        if (preg_match('/^deferred\.(mel|defects):(\d+)$/', $data, $matches)) {
            $this->sendDeferredDetails($chatId, $account, (int) $matches[2], $matches[1]);
        }
    }

    private function sendRosterList(int $chatId, TelegramAccount $account): void
    {
        $items = RosterItem::query()
            ->where('user_id', $account->user_id)
            ->where('is_actual', true)
            ->where('is_removed_from_source', false)
            ->whereBetween('starts_at', [now()->subDays(7), now()->addDays(7)])
            ->orderBy('starts_at')
            ->limit(30)
            ->get();

        if ($items->isEmpty()) {
            $this->client->sendMessage($chatId, 'На неделю до/после актуальных элементов плана пока нет.');
            return;
        }

        foreach ($items as $item) {
            $buttons = [];
            if ($item->flightSegments()->exists()) {
                $buttons = [[['text' => 'Подробнее', 'callback_data' => 'details.flight:'.$item->id]]];
            }

            $this->client->sendMessage($chatId, $this->formatRosterItem($item), [
                'reply_markup' => $buttons ? ['inline_keyboard' => $buttons] : null,
            ]);
        }

        $this->client->sendMessage($chatId, 'Готово.', ['reply_markup' => $this->mainKeyboard($account)]);
    }

    private function sendNearestRosterItem(int $chatId, TelegramAccount $account): void
    {
        $item = RosterItem::query()
            ->where('user_id', $account->user_id)
            ->where('is_actual', true)
            ->where('is_removed_from_source', false)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        if (! $item) {
            $this->client->sendMessage($chatId, 'Ближайшее кольцо или элемент плана пока не найден.');
            return;
        }

        $this->client->sendMessage($chatId, $this->formatRosterItem($item), [
            'reply_markup' => $item->flightSegments()->exists()
                ? ['inline_keyboard' => [[['text' => 'Подробнее', 'callback_data' => 'details.flight:'.$item->id]]]]
                : $this->mainKeyboard($account),
        ]);
    }

    private function sendNearestFlightSegment(int $chatId, TelegramAccount $account): void
    {
        $segment = FlightSegment::query()
            ->where('user_id', $account->user_id)
            ->withActualRosterItem()
            ->where('starts_at', '>=', now())
            ->with(['crewMembers', 'deferredItems'])
            ->orderBy('starts_at')
            ->first();

        if (! $segment) {
            $this->client->sendMessage($chatId, 'Ближайший рейс пока не найден.');
            return;
        }

        $keyboard = $this->flightDeferredKeyboard($segment);
        $this->client->sendMessage($chatId, $this->formatFlightSegment($segment), [
            'reply_markup' => $keyboard ?: $this->mainKeyboard($account),
        ]);
    }

    private function sendFlightDetails(int $chatId, TelegramAccount $account, int $rosterItemId): void
    {
        $segments = FlightSegment::query()
            ->where('user_id', $account->user_id)
            ->withActualRosterItem()
            ->where('roster_item_id', $rosterItemId)
            ->with(['crewMembers', 'deferredItems'])
            ->orderBy('starts_at')
            ->get();

        if ($segments->isEmpty()) {
            $this->client->sendMessage($chatId, 'Детали рейсов для этого элемента плана пока не найдены.');
            return;
        }

        foreach ($segments as $segment) {
            $this->client->sendMessage($chatId, $this->formatFlightSegment($segment), [
                'reply_markup' => $this->flightDeferredKeyboard($segment),
            ]);
        }
    }

    private function sendDeferredDetails(
        int $chatId,
        TelegramAccount $account,
        int $flightSegmentId,
        string $group
    ): void
    {
        $segment = FlightSegment::query()
            ->whereKey($flightSegmentId)
            ->where('user_id', $account->user_id)
            ->withActualRosterItem()
            ->with('deferredItems')
            ->first();

        if (! $segment) {
            $this->client->sendMessage($chatId, 'Данные рейса не найдены.');
            return;
        }

        $items = $this->deferredItemsForGroup($segment, $group);
        if ($items->isEmpty()) {
            $this->client->sendMessage($chatId, 'Записей этого типа для рейса нет.');
            return;
        }

        $lines = [
            '<b>'.$this->deferredGroupTitle($group).' — рейс '.$this->e($segment->flight_number ?: 'без номера').'</b>',
        ];

        foreach ($items->values() as $index => $item) {
            $lines[] = '';
            $lines = array_merge($lines, $this->formatDeferredItem($item, $index + 1));
        }

        $this->client->sendMessage($chatId, implode("\n", $lines));
    }

    private function sendCalendarLink(int $chatId, TelegramAccount $account): void
    {
        $feed = CalendarFeed::query()->firstOrCreate(
            ['user_id' => $account->user_id, 'name' => 'Telegram'],
            [
                'token' => Str::random(80),
                'is_active' => true,
                'include_crew' => false,
                'include_phones' => false,
                'include_deferred' => false,
            ]
        );

        $url = rtrim((string) config('app.url'), '/').'/api/calendar/'.$feed->token.'.ics';

        $this->client->sendMessage($chatId, "Ваша приватная ссылка календаря:\n".$this->e($url));
    }

    private function sendPendingUsers(int $chatId): void
    {
        $accounts = TelegramAccount::query()
            ->with('user')
            ->whereHas('user', function ($query) {
                $query->where('status', 'pending');
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        if ($accounts->isEmpty()) {
            $this->client->sendMessage($chatId, 'Новых заявок нет.');
            return;
        }

        foreach ($accounts as $account) {
            $this->sendPendingApprovalRequest($chatId, $account);
        }
    }

    private function notifyAdminsAboutPendingUser(TelegramAccount $pendingAccount): void
    {
        $admins = TelegramAccount::query()
            ->where('is_admin', true)
            ->whereHas('user', function ($query) {
                $query->where('status', 'active');
            })
            ->get();

        foreach ($admins as $admin) {
            $this->sendPendingApprovalRequest((int) $admin->telegram_id, $pendingAccount);
        }
    }

    private function sendPendingApprovalRequest(int $chatId, TelegramAccount $pendingAccount): void
    {
        $personnelNumber = $this->portalLogin($pendingAccount->user);
        $this->client->sendMessage(
            $chatId,
            sprintf(
                "Новая заявка на доступ:\n%s\nТабельный номер: <code>%s</code>",
                $this->e($pendingAccount->user->display_name ?: 'Без имени'),
                $this->e($personnelNumber ?: 'не указан')
            ),
            [
                'reply_markup' => [
                    'inline_keyboard' => [[[
                        'text' => 'Одобрить',
                        'callback_data' => 'admin.approve:'.$pendingAccount->id,
                    ]]],
                ],
            ]
        );
    }

    private function sendHelp(int $chatId, TelegramAccount $account): void
    {
        $text = "Команды:\n/start - открыть бота\n/help - помощь\n";

        if ($this->isAdmin($account)) {
            $text .= "\nАдмин:\n/pending - заявки\n/approve TG_ID - одобрить пользователя\n/adduser TG_ID - заранее добавить пользователя";
        }

        $this->client->sendMessage($chatId, $text);
    }

    private function sendPendingMessage(int $chatId, TelegramAccount $account): void
    {
        $this->client->sendMessage(
            $chatId,
            "Заявка сохранена и ждёт подтверждения администратором.\nВаш Telegram ID: <code>{$account->telegram_id}</code>"
        );
    }

    private function formatRosterItem(RosterItem $item): string
    {
        return implode("\n", array_filter([
            '<b>'.$this->e($item->title ?: $item->kind).'</b>',
            'Дата и время: '.$this->formatDate($item->starts_at).' UTC',
            $item->flight_numbers_raw ? 'Рейс: '.$this->e($item->flight_numbers_raw) : null,
            $item->route_raw ? 'Маршрут: '.$this->e($item->route_raw) : null,
            $item->aircraft_type_raw ? 'Тип ВС: '.$this->e($item->aircraft_type_raw) : null,
            $item->boards_raw ? 'Борт: '.$this->e($item->boards_raw) : null,
        ]));
    }

    private function formatFlightSegment(FlightSegment $segment): string
    {
        $lines = array_filter([
            '<b>Рейс '.$this->e($segment->flight_number ?: 'без номера').'</b>',
            $segment->route_raw ? 'Маршрут: '.$this->e($segment->route_raw) : null,
            'Вылет: '.$this->formatDate($segment->starts_at).' UTC',
            $segment->ends_at ? 'Прилёт: '.$this->formatDate($segment->ends_at).' UTC' : null,
            $segment->aircraft_type ? 'Тип ВС: '.$this->e($segment->aircraft_type) : null,
            $segment->board ? 'Борт: <a href="https://www.flightradar24.com/data/aircraft/ra-'.$this->e($segment->board).'">'.$this->e($segment->board).'</a>' : null,
            $segment->purpose ? 'Цель: '.$this->e($segment->purpose) : null,
            $segment->parking_minutes ? 'Остановка: '.$this->formatParking((int) $segment->parking_minutes) : null,
            $segment->open_doc_url ? '<a href="'.$this->e($segment->open_doc_url).'">Просмотреть задание</a>' : null,
            $segment->download_doc_url ? '<a href="'.$this->e($segment->download_doc_url).'">Скачать задание</a>' : null,
        ]);

        if ($segment->crewMembers->isNotEmpty()) {
            $lines[] = "\n<b>Экипаж:</b>";
            foreach ($segment->crewMembers as $index => $crew) {
                $lines[] = ($index + 1).') '.$this->e(trim(($crew->role ?: '').' '.$crew->full_name));
                foreach ($crew->phones ?? [] as $phone) {
                    $lines[] = $this->e($phone);
                }
            }
        }

        foreach (['mel', 'defects'] as $group) {
            $warnings = $this->deferredItemsForGroup($segment, $group)
                ->where('is_warning', true)
                ->values();

            if ($warnings->isEmpty()) {
                continue;
            }

            $lines[] = "\n<b>⚠️ ".$this->deferredGroupTitle($group).':</b>';
            foreach ($warnings as $index => $item) {
                if ($index > 0) {
                    $lines[] = '';
                }
                $lines[] = ($index + 1).') '.$this->e($item->title ?: 'Без описания');
                if ($item->work_order) {
                    $lines[] = 'W/O: '.$this->e($item->work_order);
                }
                if ($date = $this->deferredDate($item)) {
                    $lines[] = 'Date: '.$this->e($date);
                }
            }
        }

        return implode("\n", $lines);
    }

    private function flightDeferredKeyboard(FlightSegment $segment): ?array
    {
        $rows = [];
        $melCount = $this->deferredItemsForGroup($segment, 'mel')->count();
        $defectsCount = $this->deferredItemsForGroup($segment, 'defects')->count();

        if ($melCount > 0) {
            $rows[] = [[
                'text' => 'Все MEL ('.$melCount.')',
                'callback_data' => 'deferred.mel:'.$segment->id,
            ]];
        }
        if ($defectsCount > 0) {
            $rows[] = [[
                'text' => 'Все DEFERRED DEFECTS ('.$defectsCount.')',
                'callback_data' => 'deferred.defects:'.$segment->id,
            ]];
        }

        return $rows === [] ? null : ['inline_keyboard' => $rows];
    }

    private function deferredItemsForGroup(FlightSegment $segment, string $group)
    {
        return $segment->deferredItems->filter(function (FlightDeferredItem $item) use ($group) {
            $name = mb_strtoupper((string) $item->group_name);

            return $group === 'mel'
                ? str_contains($name, 'MEL')
                : str_contains($name, 'DEFECT');
        });
    }

    private function deferredGroupTitle(string $group): string
    {
        return $group === 'mel' ? 'DEFERRED ITEMS ACCORDING MEL' : 'DEFERRED DEFECTS';
    }

    private function formatDeferredItem(FlightDeferredItem $item, int $number): array
    {
        $lines = [
            '<b>'.$number.'. '.$this->e($item->title ?: 'Без описания').'</b>',
        ];

        if ($item->is_warning) {
            $lines[] = '⚠️ Требует внимания';
        }

        $fields = [
            'W/O' => $item->work_order,
            'Date' => $this->deferredDate($item),
            'MEL' => $item->mel,
            'ATA' => $item->ata,
            'TAH' => $item->tah,
            'TAC' => $item->tac,
        ];

        foreach ($fields as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $label.': '.$this->e((string) $value);
            }
        }

        $knownFields = ['WO', 'WORKORDER', 'DATE', 'MEL', 'ATA', 'DESCRIPTION', 'DEFECT', 'TAH', 'TAC'];
        foreach ($item->raw_data ?? [] as $label => $value) {
            $normalizedLabel = mb_strtoupper(preg_replace('/[^\pL\pN]/u', '', (string) $label));
            if (in_array($normalizedLabel, $knownFields, true) || $value === null || $value === '') {
                continue;
            }
            $lines[] = $this->e((string) $label).': '.$this->e(is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        return $lines;
    }

    private function deferredDate(FlightDeferredItem $item): ?string
    {
        foreach ($item->raw_data ?? [] as $label => $value) {
            if (mb_strtoupper(trim((string) $label)) === 'DATE' && $value !== null && $value !== '') {
                return (string) $value;
            }
        }

        $parts = [];
        if ($item->issued_at) {
            $parts[] = 'Iss: '.$item->issued_at->utc()->format('d.m.Y');
        }
        if ($item->due_at) {
            $parts[] = 'Due: '.$item->due_at->utc()->format('d.m.Y');
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    private function mainKeyboard(TelegramAccount $account): array
    {
        $keyboard = [
            [['text' => 'Список рейсов']],
            [['text' => 'Ближайшее кольцо'], ['text' => 'Ближайший рейс']],
            [['text' => 'Мой календарь'], ['text' => 'Сменить пароль']],
        ];

        if ($this->isAdmin($account)) {
            $keyboard[] = [['text' => 'Заявки на доступ']];
        }

        return [
            'keyboard' => $keyboard,
            'one_time_keyboard' => false,
            'resize_keyboard' => true,
        ];
    }

    private function findOrRegisterTelegramAccount(array $from): TelegramAccount
    {
        $telegramId = (int) $from['id'];
        $account = TelegramAccount::query()->where('telegram_id', $telegramId)->with('user')->first();

        if ($account) {
            return $account;
        }

        $user = User::query()->create([
            'display_name' => null,
            'status' => 'pending',
        ]);

        return TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_id' => $telegramId,
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
            'is_admin' => false,
        ])->load('user');
    }

    private function syncTelegramProfile(TelegramAccount $account, array $from): void
    {
        $account->forceFill([
            'username' => $from['username'] ?? $account->username,
            'first_name' => $from['first_name'] ?? $account->first_name,
            'last_name' => $from['last_name'] ?? $account->last_name,
        ])->save();
    }

    private function hasPortalCredentials(User $user): bool
    {
        return PortalCredential::query()
            ->where('user_id', $user->id)
            ->where('portal', 'rossiya_edu')
            ->exists();
    }

    private function savePortalCredentials(User $user, string $login, string $password): void
    {
        PortalCredential::query()->updateOrCreate(
            ['user_id' => $user->id, 'portal' => 'rossiya_edu'],
            [
                'login' => $login,
                'password_encrypted' => Crypt::encryptString($password),
                'key_version' => 1,
                'status' => 'active',
            ]
        );
    }

    private function portalLogin(User $user): string
    {
        return (string) PortalCredential::query()
            ->where('user_id', $user->id)
            ->where('portal', 'rossiya_edu')
            ->value('login');
    }

    private function setConversation(TelegramAccount $account, string $state, array $payload = []): void
    {
        $account->conversations()->whereNull('expires_at')->update(['expires_at' => now()]);
        $account->conversations()->create([
            'state' => $state,
            'payload' => $payload,
        ]);
    }

    private function closeConversation($conversation): void
    {
        $conversation->forceFill(['expires_at' => now()])->save();
    }

    private function isAdmin(TelegramAccount $account): bool
    {
        return $account->is_admin && $this->isActiveUser($account);
    }

    private function isActiveUser(TelegramAccount $account): bool
    {
        return $account->user && $account->user->status === 'active';
    }

    private function formatDate($value): string
    {
        return Carbon::parse($value)->utc()->format('d.m.Y H:i');
    }

    private function formatParking(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' мин.';
        }

        return intdiv($minutes, 60).' ч. '.($minutes % 60).' мин.';
    }

    private function e(?string $value): string
    {
        return e((string) $value);
    }
}
