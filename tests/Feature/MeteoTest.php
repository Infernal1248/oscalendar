<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MeteoReports;
use Illuminate\Support\Facades\{Artisan, DB, Http};
use Tests\TestCase;

class MeteoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate');
        Http::preventStrayRequests();
    }

    public function test_request_resolves_codes_and_names_and_returns_partial_results_on_each_refresh(): void
    {
        $this->mock(MeteoReports::class)->shouldReceive('fetch')
            ->twice()->with(['UUEE', 'ULLI'])
            ->andReturn(['UUEE' => 'METAR UUEE 251200Z 00000KT', 'ULLI' => null],
                ['UUEE' => 'METAR UUEE 251230Z 00000KT', 'ULLI' => null]);
        $this->actingAs(User::create(['status' => 'active']), 'sanctum');
        $payload = ['airports' => 'Москва (Шереметьево), SVO, LED, АБАН, UNKNOWN'];

        $response = $this->postJson('/api/meteo', $payload)->assertOk()->assertJsonCount(4, 'results')
            ->assertJsonPath('results.0.icao', 'UUEE')
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.report', 'METAR UUEE 251200Z 00000KT')
            ->assertJsonPath('results.1.icao', 'ULLI')
            ->assertJsonPath('results.1.status', 'unavailable')
            ->assertJsonPath('results.2.status', 'missing_icao')
            ->assertJsonPath('results.3.status', 'unknown_airport');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->postJson('/api/meteo', $payload)->assertOk()
            ->assertJsonPath('results.0.report', 'METAR UUEE 251230Z 00000KT');
    }

    public function test_access_and_input_limits_are_enforced_without_calling_the_source(): void
    {
        $this->mock(MeteoReports::class)->shouldNotReceive('fetch');
        $this->postJson('/api/meteo', ['airports' => 'SVO'])->assertUnauthorized();
        $this->getJson('/api/meteo')->assertUnauthorized();
        $this->postJson('/api/meteo/refresh')->assertUnauthorized();
        $this->actingAs(User::create(['status' => 'blocked']), 'sanctum');
        $this->postJson('/api/meteo', ['airports' => 'SVO'])->assertForbidden();
        $this->getJson('/api/meteo')->assertForbidden();
        $this->postJson('/api/meteo/refresh')->assertForbidden();

        $this->actingAs(User::create(['status' => 'active']), 'sanctum');
        $this->getJson('/api/meteo')->assertOk()->assertExactJson(['request' => null]);
        $this->postJson('/api/meteo/refresh')->assertNotFound();
        foreach (['', ', ,', str_repeat('SVO ', 11)] as $input) {
            $this->postJson('/api/meteo', ['airports' => $input])->assertUnprocessable()
                ->assertJsonValidationErrors('airports');
        }
        $this->postJson('/api/meteo', ['airports' => 'UNKNOWN'])->assertOk()
            ->assertJsonPath('results.0.status', 'unknown_airport');
    }

    public function test_last_request_is_shared_by_the_account_and_replaced_only_when_reports_are_received(): void
    {
        $five = ['UUEE', 'ULLI', 'USSS', 'UNKL', 'UNNT'];
        $six = [...$five, 'UUWW'];
        $fiveAirports = 'SVO LED SVX KJA OVB';
        $sixAirports = 'SVO LED SVX KJA OVB VKO';
        $meteo = $this->mock(MeteoReports::class);
        $meteo->shouldReceive('fetch')->twice()->with($five)->andReturn(
            array_fill_keys($five, 'METAR 251200Z'),
            array_fill_keys($five, 'METAR 251230Z'),
        );
        $meteo->shouldReceive('fetch')->twice()->with($six)->andReturn(
            array_fill_keys($six, 'METAR 251300Z'),
            array_fill_keys($six, null),
        );
        $meteo->shouldReceive('fetch')->once()->with(['UUEE'])->andThrow(new \RuntimeException);
        $user = User::create(['status' => 'active']);
        $tablet = $user->createToken('tablet')->plainTextToken;
        $computer = $user->createToken('computer')->plainTextToken;

        $initial = $this->withToken($tablet)->postJson('/api/meteo', ['airports' => $fiveAirports])
            ->assertOk()->assertJsonCount(5, 'results')->assertJsonPath('saved', true);
        $this->app['auth']->forgetGuards();
        $restored = $this->withToken($computer)->getJson('/api/meteo')->assertOk()
            ->assertJsonPath('request.airports', $fiveAirports)
            ->assertJsonPath('request.received_at', $initial->json('received_at'))
            ->assertJsonPath('request.results', $initial->json('results'));
        $this->assertTrue($restored->headers->hasCacheControlDirective('no-store'));
        $this->postJson('/api/meteo/refresh', ['airports' => 'VKO'])->assertOk()
            ->assertJsonPath('airports', $fiveAirports)->assertJsonCount(5, 'results')
            ->assertJsonPath('results.0.report', 'METAR 251230Z');

        $replacement = $this->postJson('/api/meteo', ['airports' => $sixAirports])->assertOk()
            ->assertJsonPath('airports', $sixAirports)->assertJsonCount(6, 'results');
        $this->postJson('/api/meteo/refresh')->assertOk()->assertJsonPath('saved', false);
        $this->postJson('/api/meteo', ['airports' => 'SVO'])->assertServiceUnavailable();
        $this->getJson('/api/meteo')->assertOk()
            ->assertJsonPath('request.airports', $sixAirports)->assertJsonCount(6, 'request.results')
            ->assertJsonPath('request.received_at', $replacement->json('received_at'))
            ->assertJsonPath('request.results', $replacement->json('results'));
        $this->assertDatabaseCount('meteo_last_requests', 1);

        $this->flushHeaders()->actingAs(User::create(['status' => 'active']), 'sanctum')
            ->getJson('/api/meteo')->assertOk()->assertExactJson(['request' => null]);
    }

    public function test_an_in_flight_refresh_keeps_a_newer_list_submitted_on_another_device(): void
    {
        $user = User::create(['status' => 'active']);
        $this->actingAs($user, 'sanctum');
        $meteo = $this->mock(MeteoReports::class);
        $meteo->shouldReceive('fetch')->once()->with(['UUEE'])->andReturn(['UUEE' => 'METAR 251200Z']);
        $this->postJson('/api/meteo', ['airports' => 'SVO'])->assertOk();

        $meteo->shouldReceive('fetch')->once()->with(['UUEE'])->andReturnUsing(function () use ($user) {
            DB::table('meteo_last_requests')->where('user_id', $user->id)->update([
                'airports' => 'LED',
                'results' => json_encode([['icao' => 'ULLI', 'status' => 'ok', 'report' => 'METAR 251230Z']]),
                'received_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            ]);

            return ['UUEE' => 'METAR 251240Z'];
        });

        $this->postJson('/api/meteo/refresh')->assertOk()
            ->assertJsonPath('airports', 'LED')->assertJsonPath('results.0.icao', 'ULLI');
        $this->getJson('/api/meteo')->assertOk()->assertJsonPath('request.airports', 'LED')
            ->assertJsonPath('request.results.0.report', 'METAR 251230Z');
    }
}
