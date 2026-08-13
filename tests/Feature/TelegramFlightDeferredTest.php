<?php

namespace Tests\Feature;

use App\Models\FlightSegment;
use App\Models\RosterItem;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PDO;
use Tests\TestCase;

class TelegramFlightDeferredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('The pdo_sqlite extension is required.');
        }

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'services.telegram_bot.token' => 'test-bot-token',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Carbon::setTestNow('2026-08-10 00:00:00');
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 501],
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flight_message_shows_warnings_and_buttons_open_complete_group_details(): void
    {
        [$account, $segment] = $this->flightForTelegramUser(123456);

        $segment->crewMembers()->create([
            'role' => 'КВС',
            'full_name' => 'Фраиндт Роман Александрович',
            'phones' => ['+79690290525', '+79999667434'],
        ]);
        $segment->deferredItems()->create([
            'group_name' => 'DEFERRED ITEMS ACCORDING MEL',
            'title' => 'Warning MEL item',
            'work_order' => '517962341',
            'issued_at' => '2026-08-04 00:00:00',
            'due_at' => '2026-08-09 00:00:00',
            'mel' => 'D 25-43-00',
            'ata' => '25-42-00',
            'is_warning' => true,
            'raw_data' => [
                'W/O' => '517962341',
                'Date' => 'Iss: 04.08.2026 Due: 09.08.2026',
                'Station' => 'SVO',
            ],
        ]);
        $segment->deferredItems()->create([
            'group_name' => 'DEFERRED ITEMS ACCORDING MEL',
            'title' => 'Future MEL item',
            'work_order' => '517962342',
            'due_at' => '2026-12-02 00:00:00',
            'is_warning' => false,
            'raw_data' => ['Date' => 'Due: 02.12.2026'],
        ]);
        $segment->deferredItems()->create([
            'group_name' => 'DEFERRED DEFECTS',
            'title' => 'Warning deferred defect',
            'work_order' => '509032521',
            'due_at' => '2026-08-08 00:00:00',
            'tah' => '13988',
            'tac' => '7569',
            'is_warning' => true,
            'raw_data' => ['Date' => 'Due: 08.08.2026 TAH: 13988 TAC: 7569'],
        ]);

        app(TelegramBotService::class)->handle(['message' => [
            'chat' => ['id' => $account->telegram_id],
            'from' => ['id' => $account->telegram_id],
            'text' => 'Ближайший рейс',
        ]]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], "1) КВС Фраиндт Роман Александрович\n+79690290525\n+79999667434")
            && str_contains((string) $request['text'], '⚠️ DEFERRED ITEMS ACCORDING MEL')
            && str_contains((string) $request['text'], 'Warning MEL item')
            && str_contains((string) $request['text'], 'W/O: 517962341')
            && str_contains((string) $request['text'], 'Date: Iss: 04.08.2026 Due: 09.08.2026')
            && ! str_contains((string) $request['text'], 'Future MEL item')
            && ($request['reply_markup']['inline_keyboard'][0][0] ?? null) === [
                'text' => 'Все MEL (2)',
                'callback_data' => 'deferred.mel:'.$segment->id,
            ]
            && ($request['reply_markup']['inline_keyboard'][1][0] ?? null) === [
                'text' => 'Все DEFERRED DEFECTS (1)',
                'callback_data' => 'deferred.defects:'.$segment->id,
            ]);

        app(TelegramBotService::class)->handle(['callback_query' => [
            'id' => 'mel-callback',
            'from' => ['id' => $account->telegram_id],
            'message' => ['chat' => ['id' => $account->telegram_id]],
            'data' => 'deferred.mel:'.$segment->id,
        ]]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'DEFERRED ITEMS ACCORDING MEL — рейс ФВ6363')
            && str_contains((string) $request['text'], 'Warning MEL item')
            && str_contains((string) $request['text'], 'Future MEL item')
            && str_contains((string) $request['text'], 'MEL: D 25-43-00')
            && str_contains((string) $request['text'], 'ATA: 25-42-00')
            && str_contains((string) $request['text'], 'Station: SVO'));

        app(TelegramBotService::class)->handle(['callback_query' => [
            'id' => 'defects-callback',
            'from' => ['id' => $account->telegram_id],
            'message' => ['chat' => ['id' => $account->telegram_id]],
            'data' => 'deferred.defects:'.$segment->id,
        ]]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'DEFERRED DEFECTS — рейс ФВ6363')
            && str_contains((string) $request['text'], 'Warning deferred defect')
            && str_contains((string) $request['text'], 'TAH: 13988')
            && str_contains((string) $request['text'], 'TAC: 7569'));
    }

    public function test_deferred_callback_cannot_read_another_users_segment(): void
    {
        [, $foreignSegment] = $this->flightForTelegramUser(123456);
        [$account] = $this->flightForTelegramUser(654321);
        $foreignSegment->deferredItems()->create([
            'group_name' => 'DEFERRED DEFECTS',
            'title' => 'Private defect',
            'is_warning' => true,
        ]);

        app(TelegramBotService::class)->handle(['callback_query' => [
            'id' => 'foreign-callback',
            'from' => ['id' => $account->telegram_id],
            'message' => ['chat' => ['id' => $account->telegram_id]],
            'data' => 'deferred.defects:'.$foreignSegment->id,
        ]]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === $account->telegram_id
            && $request['text'] === 'Данные рейса не найдены.');
        Http::assertNotSent(fn ($request) => $request['chat_id'] === $account->telegram_id
            && str_contains((string) ($request['text'] ?? ''), 'Private defect'));
    }

    private function flightForTelegramUser(int $telegramId): array
    {
        $user = User::query()->create(['display_name' => 'Telegram User', 'status' => 'active']);
        $account = TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_id' => $telegramId,
        ]);
        $rosterItem = RosterItem::query()->create([
            'user_id' => $user->id,
            'source_external_id' => 'roster-'.$telegramId,
            'kind' => 'flight_ring',
            'starts_at' => '2026-08-12 08:15:00',
            'is_actual' => true,
            'is_removed_from_source' => false,
        ]);
        $segment = FlightSegment::query()->create([
            'user_id' => $user->id,
            'roster_item_id' => $rosterItem->id,
            'source_para_id' => 'para-'.$telegramId,
            'flight_number' => 'ФВ6363',
            'route_raw' => 'ШЕРЕМЕТ - ТЮМЕНЬ',
            'starts_at' => '2026-08-12 08:15:00',
            'ends_at' => '2026-08-12 11:05:00',
        ]);

        return [$account, $segment];
    }
}
