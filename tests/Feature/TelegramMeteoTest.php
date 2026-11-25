<?php

namespace Tests\Feature;

use App\Models\{FlightSegment, RosterItem, TelegramAccount, User};
use App\Services\MeteoReports;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\{Artisan, DB, Http};
use Tests\TestCase;

class TelegramMeteoTest extends TestCase
{
    private const SOURCE = 'https://meteo.test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'services.telegram_bot.token' => '123456:fixture-bot-token',
            'services.telegram_bot.api_base' => 'https://tg.volodimir-k.su',
            'services.meteo' => ['base_url' => self::SOURCE, 'login' => 'fixture', 'password' => 'pass & word'],
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate');
        Http::preventStrayRequests();
    }

    private function fakeMeteo(callable $reports): void
    {
        Http::fake([
            'tg.volodimir-k.su/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            self::SOURCE.'/' => fn () => Http::response('', 302, ['Location' => '/', 'Set-Cookie' => 'session=authorized; Path=/; Secure']),
            self::SOURCE.'/app/lib/bamd.php*' => function (Request $request) use ($reports) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertTrue($request->hasHeader('Cookie', 'session=authorized'));
                $this->assertArrayNotHasKey('pass', $query);
                return $reports(substr($query['query'], -4));
            },
        ]);
    }

    private function message(int $id, string $text): void
    {
        app(TelegramBotService::class)->handle([
            'message' => ['text' => $text, 'chat' => ['id' => $id], 'from' => ['id' => $id]],
        ]);
    }

    private function reportRequests(): array
    {
        return Http::recorded(fn ($request) => str_starts_with($request->url(), self::SOURCE.'/app/lib/bamd.php'))
            ->map(function ($pair) {
                parse_str(parse_url($pair[0]->url(), PHP_URL_QUERY), $query);
                return $query['query'];
            })->values()->all();
    }

    public function test_manual_input_requires_meteo_mode_and_gets_new_reports_using_one_session(): void
    {
        $this->fakeMeteo(fn ($code) => Http::response("<pre>METAR $code 171200Z 00000KT</pre>"));
        $user = User::create(['status' => 'active']);
        $account = TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 55]);
        $this->message(55, 'UUEE');
        $this->assertSame([], $this->reportRequests());

        foreach (['Ввести коды аэропортов', 'svo LED UUEE АБАН', 'Ввести коды аэропортов', 'Шереметьево'] as $text) {
            $this->message(55, $text);
        }
        $this->assertSame(['SA FT FC UUEE', 'SA FT FC ULLI', 'SA FT FC UUEE'], $this->reportRequests());
        $this->assertCount(1, Http::recorded(fn ($request) => $request->url() === self::SOURCE.'/'));
        Http::assertSent(fn ($request) => ($request['text'] ?? '') === 'АБАН — нет латинского ICAO.');
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'METAR ULLI 171200Z 00000KT'));
        $this->assertNull($account->activeConversation());

        $this->message(55, 'Ввести коды аэропортов');
        $this->message(55, 'Сменить пароль');
        $this->assertSame('portal.change_password', $account->activeConversation()->state);
        $this->assertCount(3, $this->reportRequests());
    }

    public function test_own_assignment_lists_each_station_once_and_keeps_successful_reports_on_partial_failure(): void
    {
        $this->fakeMeteo(fn ($code) => $code === 'ULMM'
            ? Http::response('service unavailable', 503)
            : Http::response("<span>METAR $code 171200Z 00000KT</span>"));
        $user = User::create(['status' => 'active', 'role' => 'admin']);
        TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 55]);
        $other = User::create(['status' => 'active']);
        RosterItem::create(['user_id' => $other->id, 'kind' => 'flight_ring',
            'route_raw' => 'ШЕРЕМЕТ / ЕКАТЕРИН', 'starts_at' => now()->addMinute()]);
        $assignment = RosterItem::create(['user_id' => $user->id, 'kind' => 'flight_ring',
            'route_raw' => 'ШЕРЕМЕТ-B / МУРМАНСК / ШЕРЕМЕТ-В [прил. 17.11.2026 18:00 UTC]', 'starts_at' => now()->addHour()]);
        FlightSegment::create(['user_id' => $user->id, 'roster_item_id' => $assignment->id,
            'source_para_id' => 'fixture', 'flight_number' => 'ФВ6478', 'route_raw' => 'МУРМАНСК - ПУЛКОВО-1',
            'starts_at' => now()->addHours(2)]);

        $this->message(55, 'Метео по полётному заданию');
        $this->assertSame(['SA FT FC UUEE', 'SA FT FC ULMM', 'SA FT FC ULLI'], $this->reportRequests());
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'METAR UUEE'));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', "ULMM · МУРМАНСК\nНе удалось получить сводку."));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'METAR ULLI'));
    }

    public function test_expired_session_is_renewed_once_and_login_html_is_never_returned_as_weather(): void
    {
        $first = true;
        $this->fakeMeteo(function ($code) use (&$first) {
            if ($first) {
                $first = false;
                return Http::response('<form><input type="password" name="pass"></form>');
            }
            return Http::response("<pre>METAR $code 171200Z 00000KT<br>TAF $code 171100Z 1712/1812</pre>");
        });

        $this->assertSame(['UUEE' => "METAR UUEE 171200Z 00000KT\nTAF UUEE 171100Z 1712/1812"],
            app(MeteoReports::class)->fetch(['UUEE']));
        $this->assertCount(2, Http::recorded(fn ($request) => $request->url() === self::SOURCE.'/'));
    }

    public function test_http_endpoint_can_authenticate_and_return_a_report(): void
    {
        config(['services.meteo.base_url' => 'http://meteoinfo.gamc.ru']);
        Http::fake([
            'http://meteoinfo.gamc.ru/' => Http::response('', 302, [
                'Location' => '/',
                'Set-Cookie' => 'session=authorized; Path=/; HttpOnly',
            ]),
            'http://meteoinfo.gamc.ru/app/lib/bamd.php*' => Http::response('<pre>METAR UUEE 171200Z 00000KT</pre>'),
        ]);

        $this->assertSame(['UUEE' => 'METAR UUEE 171200Z 00000KT'], app(MeteoReports::class)->fetch(['UUEE']));
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'http://meteoinfo.gamc.ru/' && $request['login'] === 'fixture' && $request['pass'] === 'pass & word');
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'http://meteoinfo.gamc.ru/app/lib/bamd.php?')
            && $request->hasHeader('Cookie', 'session=authorized'));
        Http::assertSentCount(2);
    }
}
