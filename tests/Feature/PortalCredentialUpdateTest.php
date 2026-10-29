<?php

namespace Tests\Feature;

use App\Models\{InternalApiToken, ParserTask, PortalCredential, TelegramAccount, User};
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\{Artisan, Crypt, DB, Http};
use Tests\TestCase;

class PortalCredentialUpdateTest extends TestCase
{
    private User $user;
    private PortalCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-29 10:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'services.telegram_bot.token' => 'test-token']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->user = User::create(['status' => 'active']);
        $this->credential = PortalCredential::create(['user_id' => $this->user->id, 'portal' => 'rossiya_edu',
            'login' => 'old-login', 'password_encrypted' => Crypt::encryptString('old-password'), 'status' => 'active']);
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('internal-test'), 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function update(array $data = [])
    {
        return $this->actingAs($this->user, 'sanctum')->patchJson('/api/account/portal-credentials', $data + [
            'login' => 'new-login', 'password' => ' new-password ', 'password_confirmation' => ' new-password ',
        ]);
    }

    private function claim(): array
    {
        return $this->withToken('internal-test')->postJson('/api/internal/parser-jobs/claim', [
            'source' => 'rossiya_edu', 'portal' => 'rossiya_edu', 'user_id' => $this->user->id,
            'locked_by' => 'test-supervisor', 'lock_seconds' => 900, 'capabilities' => ['typed_tasks_v1'],
        ])->assertOk()->json('job');
    }

    public function test_web_save_encrypts_exact_password_is_private_and_next_job_uses_new_credentials(): void
    {
        $oldRevision = hash('sha256', $this->credential->login.'|'.$this->credential->password_encrypted);
        $this->credential->forceFill(['auth_failure_started_at' => now()->subDays(2), 'auth_failure_count' => 20,
            'auth_notified_at' => now()->subHour()])->save();
        $response = $this->update()->assertOk()->assertJsonPath('portal_connection.login', 'new-login')->assertJsonPath('portal_connection.uses_portal_login', true);
        $this->assertStringNotContainsString('new-password', $response->getContent());
        $credential = $this->credential->fresh();
        $this->assertSame(' new-password ', Crypt::decryptString($credential->password_encrypted));
        $this->assertNotEquals(' new-password ', $credential->password_encrypted);
        $this->assertNull($credential->auth_failure_started_at);
        $this->assertNull($credential->auth_notified_at);
        $job = $this->claim();
        $this->assertSame('new-login', $job['login']);
        $this->assertSame(' new-password ', $job['password']);
        $this->assertNotSame($oldRevision, $job['credentials_version']);
        $this->assertSame(hash('sha256', $credential->login.'|'.$credential->password_encrypted), $job['credentials_version']);
        $this->postJson('/api/auth/login', ['login' => 'old-login', 'password' => 'old-password'])->assertUnprocessable();
        $this->postJson('/api/auth/login', ['login' => 'new-login', 'password' => ' new-password '])->assertOk();
        Http::assertNothingSent(); // Saving does not claim that a portal login already succeeded.
    }

    public function test_invalid_or_unconfirmed_password_does_not_overwrite_stored_credentials(): void
    {
        $this->update(['password_confirmation' => 'different'])->assertUnprocessable();
        $this->update(['password' => ' ', 'password_confirmation' => ' '])->assertUnprocessable();
        $this->assertSame('old-password', Crypt::decryptString($this->credential->fresh()->password_encrypted));
        $this->assertDatabaseCount('parser_tasks', 0);
    }

    public function test_update_requires_authentication_active_user_and_existing_connection(): void
    {
        $this->patchJson('/api/account/portal-credentials', [])->assertUnauthorized();
        $other = User::create(['status' => 'active']);
        $this->actingAs($other, 'sanctum')->patchJson('/api/account/portal-credentials', [])->assertNotFound();
        $this->user->update(['status' => 'blocked']);
        $this->update()->assertForbidden();
        $this->assertSame('old-password', Crypt::decryptString($this->credential->fresh()->password_encrypted));
    }

    public function test_change_during_running_job_requests_an_immediate_followup_without_rewriting_running_payload(): void
    {
        $oldJob = $this->claim();
        $this->travel(1)->seconds();
        $this->update()->assertOk();
        $task = ParserTask::findOrFail($oldJob['task_id']);
        $this->assertSame('running', $task->status);
        $this->assertTrue($task->refresh_requested);
        $this->assertSame('old-password', $oldJob['password']);
        $this->withToken('internal-test')->postJson('/api/internal/sync-runs/'.$oldJob['sync_run_id'].'/finish', [
            'status' => 'finished',
        ])->assertOk();
        $this->assertTrue($task->fresh()->next_run_at->equalTo(now()));
        $newJob = $this->claim();
        $this->assertSame(' new-password ', $newJob['password']);
        $this->assertNotSame($oldJob['credentials_version'], $newJob['credentials_version']);
    }

    public function test_telegram_password_change_preserves_spaces_and_uses_the_same_update_and_resync_flow(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        TelegramAccount::create(['user_id' => $this->user->id, 'telegram_id' => 123]);
        foreach (['Сменить пароль', ' telegram-new-password '] as $text) {
            app(TelegramBotService::class)->handle(['message' => ['from' => ['id' => 123],
                'chat' => ['id' => 123, 'type' => 'private'], 'text' => $text]]);
        }
        $this->assertSame(' telegram-new-password ', Crypt::decryptString($this->credential->fresh()->password_encrypted));
        $job = $this->claim();
        $this->assertSame('old-login', $job['login']);
        $this->assertSame(' telegram-new-password ', $job['password']);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'поставлено в очередь')
            && str_contains($r['text'], 'Для входа в OSCalendar теперь используйте сохранённый новый пароль'));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'telegram-new-password'));
    }
}
