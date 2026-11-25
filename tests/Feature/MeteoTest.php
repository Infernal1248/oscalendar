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
        $this->actingAs(User::create(['status' => 'blocked']), 'sanctum')
            ->postJson('/api/meteo', ['airports' => 'SVO'])->assertForbidden();

        $this->actingAs(User::create(['status' => 'active']), 'sanctum');
        foreach (['', ', ,', str_repeat('SVO ', 11)] as $input) {
            $this->postJson('/api/meteo', ['airports' => $input])->assertUnprocessable()
                ->assertJsonValidationErrors('airports');
        }
        $this->postJson('/api/meteo', ['airports' => 'UNKNOWN'])->assertOk()
            ->assertJsonPath('results.0.status', 'unknown_airport');
    }
}
