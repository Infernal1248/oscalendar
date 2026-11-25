<?php

namespace Tests\Feature;

use App\Models\{TelegramAccount, User};
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, DB, File, Http};
use Tests\TestCase;

class AnnounceSiteMeteoTest extends TestCase
{
    public function test_preview_does_not_send_and_rerun_only_sends_to_remaining_eligible_accounts(): void
    {
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'services.telegram_bot.token' => '123456:fixture-bot-token',
            'services.telegram_bot.api_base' => 'https://bot-hub.test',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate');
        $storage = sys_get_temp_dir().'/oscalendar-broadcast-'.bin2hex(random_bytes(8));
        $this->app->useStoragePath($storage);
        Http::preventStrayRequests();

        try {
            $active = User::create(['status' => 'active']);
            $muted = User::create(['status' => 'active']);
            $muted->forceFill(['telegram_notifications_enabled' => false])->save();
            $blocked = User::create(['status' => 'blocked']);
            foreach ([101 => $active, 102 => $active, 103 => $muted, 104 => $blocked] as $chatId => $user) {
                TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => $chatId]);
            }

            $failSecond = true;
            Http::fake(['https://bot-hub.test/*' => function (Request $request) use (&$failSecond) {
                $this->assertStringEndsWith('/sendMessage', $request->url());
                $this->assertStringContainsString('https://oscalendar.ru/meteo', $request['text']);
                $this->assertStringContainsString('AirFASE', $request['text']);
                return $request['chat_id'] === 102 && $failSecond
                    ? Http::response(['ok' => false], 503)
                    : Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
            }]);

            $this->artisan('telegram:announce-site-meteo')
                ->expectsOutput('Получателей с включёнными уведомлениями: 2')->assertSuccessful();
            Http::assertNothingSent();
            $this->assertDirectoryDoesNotExist($storage.'/app/private/telegram-announcements');

            $this->artisan('telegram:announce-site-meteo', ['--send' => true])->assertFailed();
            $failSecond = false;
            $this->artisan('telegram:announce-site-meteo', ['--send' => true])->assertSuccessful();
            $this->artisan('telegram:announce-site-meteo', ['--send' => true])->assertSuccessful();

            $this->assertSame([101, 102, 102], Http::recorded()->map(fn ($pair) => $pair[0]['chat_id'])->all());
        } finally {
            File::deleteDirectory($storage);
        }
    }
}
