<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateInternalApi;
use App\Models\InternalApiToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Artisan, DB};
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InternalApiTokenUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-30 01:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function authenticate(): int
    {
        $request = Request::create('/api/internal/parser-jobs/claim');
        $request->headers->set('Authorization', 'Bearer test-token');
        return app(AuthenticateInternalApi::class)->handle($request,
            fn ($request) => $request->attributes->get('internal_api_token_id'));
    }

    public function test_frequent_requests_check_token_each_time_but_write_usage_once_per_minute(): void
    {
        $token = InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('test-token'), 'is_active' => true]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame($token->id, $this->authenticate());
        $this->travel(59)->seconds();
        $this->assertSame($token->id, $this->authenticate());
        $this->assertSame($token->id, $this->authenticate());
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertCount(1, $queries->filter(fn ($sql) => str_starts_with($sql, 'update')));
        $this->assertCount(3, $queries->filter(fn ($sql) => str_starts_with($sql, 'select')));
        $this->assertTrue($token->fresh()->last_used_at->equalTo(now()->subSeconds(59)));
        $this->travel(1)->seconds();
        $this->authenticate();
        $this->assertTrue($token->fresh()->last_used_at->equalTo(now()));
    }

    public function test_recent_usage_does_not_cache_authorization_or_hide_revocation_and_expiry(): void
    {
        $token = InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('test-token'), 'is_active' => true]);
        $this->authenticate();
        foreach ([['is_active' => false], ['is_active' => true, 'expires_at' => now()]] as $change) {
            $token->update($change);
            try {
                $this->authenticate();
                $this->fail('Revoked or expired token must be rejected immediately.');
            } catch (HttpException $exception) {
                $this->assertSame(401, $exception->getStatusCode());
            }
        }
    }
}
