<?php

namespace Tests\Feature;

use App\Models\InternalApiToken;
use App\Models\PortalProfile;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalProfileTest extends TestCase
{
    private const PHOTO = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('profile-test'), 'is_active' => true]);
    }

    public function test_profile_sync_is_private_idempotent_and_keeps_existing_account_and_photo_on_failure(): void
    {
        $this->getJson('/api/account/photo')->assertUnauthorized();
        $user = User::create(['display_name' => 'Telegram name', 'status' => 'active']);
        $run = SyncRun::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler', 'status' => 'running', 'started_at' => now()]);
        $payload = [
            'user_id' => $user->id, 'sync_run_id' => $run->id, 'source' => 'rossiya_edu',
            'trigger' => 'scheduler', 'parsed_at' => '2026-09-15T10:00:00Z', 'chunk_kind' => 'roster',
            'is_final' => false, 'roster_source_external_id' => null, 'roster_items' => [], 'flight_segments' => [],
            'portal_profile' => ['full_name' => 'Иванов Иван Иванович', 'personnel_number' => '001234', 'photo_base64' => self::PHOTO],
        ];
        $url = "/api/internal/sync-runs/{$run->id}/partial-result";
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->withToken('profile-test')->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('portal_profiles', 1);
        $this->assertSame('Telegram name', $user->fresh()->display_name);
        $this->assertSame('image/png', $user->portalProfile->photo_mime);
        $this->assertSame('001234', $user->portalProfile->personnel_number);

        foreach ([null, ['full_name' => 'Иванов Иван Иванович', 'personnel_number' => '001234'],
            ['full_name' => 'Иванов Иван Иванович', 'personnel_number' => '001234', 'photo_base64' => base64_encode('<html>error</html>')]] as $profile) {
            $payload['portal_profile'] = $profile;
            $payload['parsed_at'] = '2026-09-15T11:00:00Z';
            $this->postJson($url, $payload)->assertOk();
            $this->assertSame(self::PHOTO, $user->portalProfile()->first()->photo_base64);
        }

        $this->actingAs($user)->getJson('/api/account')->assertOk()
            ->assertJsonPath('portal_profile.full_name', 'Иванов Иван Иванович')
            ->assertJsonMissingPath('portal_profile.photo_base64')->assertJsonMissingPath('roles');
        $this->getJson('/api/account/photo')->assertOk()->assertJsonPath('photo', 'data:image/png;base64,'.self::PHOTO)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson('/api/account', ['timezone' => 'UTC', 'portal_profile' => ['personnel_number' => '999']])->assertOk();
        $this->assertSame('001234', $user->portalProfile()->first()->personnel_number);
        $other = User::create(['status' => 'active']);
        $this->actingAs($other)->getJson('/api/account/photo?user_id='.$user->id)->assertOk()->assertJsonPath('photo', null);
        $this->getJson('/api/account')->assertJsonPath('portal_profile', null);
        $other->update(['status' => 'blocked']);
        $this->getJson('/api/account/photo')->assertForbidden();

        DB::transaction(fn () => PortalProfile::storeParsed($user->id, 'rossiya_edu', [
            'full_name' => 'Другой Пилот', 'personnel_number' => '999',
        ], '2026-09-15T09:00:00Z'));
        $this->assertSame('001234', $user->portalProfile()->first()->personnel_number);
        DB::transaction(fn () => PortalProfile::storeParsed($user->id, 'rossiya_edu', [
            'full_name' => 'Другой Пилот', 'personnel_number' => '999',
        ], '2026-09-15T12:00:00Z'));
        $this->assertNull($user->portalProfile()->first()->photo_base64);
    }

    public function test_full_sync_accepts_profile_but_rejects_invalid_identity(): void
    {
        $user = User::create(['status' => 'active']);
        $payload = ['user_id' => $user->id, 'source' => 'rossiya_edu', 'portal_profile' => [
            'full_name' => 'Иванов Иван Иванович', 'personnel_number' => '123',
        ]];
        $this->withToken('profile-test')->postJson('/api/internal/sync-result', $payload)->assertOk();
        $payload['portal_profile']['personnel_number'] = 'abc';
        $this->postJson('/api/internal/sync-result', $payload)->assertUnprocessable();
        unset($payload['portal_profile']['full_name']);
        $this->postJson('/api/internal/sync-result', $payload)->assertUnprocessable();
        $this->assertSame('123', $user->portalProfile()->first()->personnel_number);
    }
}
