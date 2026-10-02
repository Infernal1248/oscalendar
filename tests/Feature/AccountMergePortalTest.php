<?php

namespace Tests\Feature;

use App\Models\{FlightSegment, PortalCredential, RosterItem, User, ParserTask};
use App\Services\ParserTaskScheduler;
use Illuminate\Support\Facades\{Artisan, Crypt, DB, Hash};
use Tests\TestCase;

class AccountMergePortalTest extends TestCase
{
    private User $from;
    private User $to;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->from = User::create(['login' => 'oscalendar-vladimir', 'status' => 'active', 'role' => 'user']);
        $this->to = User::create(['login' => 'oscalendar-admin', 'status' => 'active', 'role' => 'admin', 'password' => Hash::make('admin-password')]);
        foreach ([$this->from, $this->to] as $user) {
            PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'portal-'.$user->id,
                'password_encrypted' => Crypt::encryptString('portal-secret-'.$user->id), 'status' => 'active']);
        }
        app(ParserTaskScheduler::class)->ensureRosterTasks('rossiya_edu', 'rossiya_edu');
    }

    private function runMerge(bool $apply = false): int
    {
        return Artisan::call('account:merge-portal', ['source' => $this->from->login, 'target' => $this->to->login,
            '--target-id' => $this->to->id, '--apply' => $apply]);
    }

    public function test_preview_then_transfer_preserves_admin_and_portal_data_and_revokes_source_access(): void
    {
        $ring = RosterItem::create(['user_id' => $this->from->id, 'source_external_id' => 'ring', 'source_request_raw' => 'request',
            'starts_at' => now(), 'ends_at' => now()->addDay()]);
        $segment = FlightSegment::create(['user_id' => $this->from->id, 'roster_item_id' => $ring->id, 'starts_at' => now()]);
        app(ParserTaskScheduler::class)->scheduleFlightDetails($ring);
        $targetTask = ParserTask::where('user_id', $this->to->id)->first();
        $this->from->createToken('web');
        $this->to->createToken('web');
        $this->from->calendarFeeds()->create(['token' => 'source-calendar']);
        $adminPassword = $this->to->password;
        $this->assertSame(0, $this->runMerge());
        $this->assertNotNull($this->from->fresh());
        $this->assertSame($this->from->id, $ring->fresh()->user_id);
        $this->assertSame(0, $this->runMerge(true), Artisan::output());
        $this->assertNull($this->from->fresh());
        $this->assertSame($this->to->id, $ring->fresh()->user_id);
        $this->assertSame($this->to->id, $segment->fresh()->user_id);
        $this->assertSame($adminPassword, $this->to->fresh()->password);
        $this->assertTrue($this->to->fresh()->isAdmin());
        $this->assertSame('portal-secret-'.$this->from->id, Crypt::decryptString($this->to->portalCredentials()->sole()->password_encrypted));
        $this->assertSame(1, ParserTask::where('user_id', $this->to->id)->where('task_type', 'roster_refresh')->count());
        $this->assertSame('scheduled', $targetTask->fresh()->status);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('calendar_feeds', 0);
        $this->assertStringNotContainsString('portal-secret', Artisan::output());
    }

    public function test_running_parser_and_telegram_identity_block_changes(): void
    {
        $task = ParserTask::where('user_id', $this->from->id)->first();
        $task->update(['status' => 'running']);
        $this->assertSame(1, $this->runMerge(true));
        $this->assertNotNull($this->from->fresh());
        $task->update(['status' => 'scheduled']);
        $this->from->telegramAccounts()->create(['telegram_id' => 123456]);
        $this->assertSame(1, $this->runMerge(true));
        $this->assertNotNull($this->from->fresh());
        $this->assertSame(2, PortalCredential::count());
    }

    public function test_selected_telegram_moves_to_admin_and_other_binding_is_removed_only_on_apply(): void
    {
        $keep = $this->from->telegramAccounts()->create(['telegram_id' => 780382888, 'username' => 'Infernal1248']);
        $old = $this->to->telegramAccounts()->create(['telegram_id' => 1695923047, 'username' => 'Vladimir1248']);
        $keep->conversations()->create(['state' => 'onboarding', 'payload' => []]);
        $arguments = ['source' => $this->from->login, 'target' => $this->to->login,
            '--target-id' => $this->to->id, '--keep-telegram-id' => 780382888];
        $this->assertSame(0, Artisan::call('account:merge-portal', $arguments));
        $this->assertSame($this->from->id, $keep->fresh()->user_id);
        $this->assertNotNull($old->fresh());
        $this->assertSame(0, Artisan::call('account:merge-portal', $arguments + ['--apply' => true]), Artisan::output());
        $this->assertSame($this->to->id, $keep->fresh()->user_id);
        $this->assertTrue($keep->fresh()->user->isAdmin());
        $this->assertTrue($keep->fresh()->is_admin);
        $this->assertNull($old->fresh());
        $this->assertDatabaseCount('telegram_conversations', 0);
        $this->assertNull($this->from->fresh());
    }

    public function test_cannot_select_telegram_of_unrelated_user(): void
    {
        $other = User::create(['status' => 'active']);
        $other->telegramAccounts()->create(['telegram_id' => 999]);
        $this->assertSame(1, Artisan::call('account:merge-portal', ['source' => $this->from->login,
            'target' => $this->to->login, '--target-id' => $this->to->id, '--keep-telegram-id' => 999, '--apply' => true]));
        $this->assertNotNull($this->from->fresh());
        $this->assertSame($other->id, \App\Models\TelegramAccount::where('telegram_id', 999)->sole()->user_id);
    }

    public function test_existing_admin_data_and_wrong_target_id_block_transfer(): void
    {
        $this->assertSame(1, Artisan::call('account:merge-portal', ['source' => $this->from->login, 'target' => $this->to->login,
            '--target-id' => 999, '--apply' => true]));
        RosterItem::create(['user_id' => $this->to->id, 'starts_at' => now()]);
        $this->assertSame(1, $this->runMerge(true));
        $this->assertNotNull($this->from->fresh());
        $this->assertSame(2, PortalCredential::count());
    }
}
