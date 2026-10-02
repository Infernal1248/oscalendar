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

    public function test_payments_orders_and_audit_references_move_without_changing_financial_data(): void
    {
        $orderId = (string) \Illuminate\Support\Str::uuid();
        DB::table('payment_orders')->insert([
            'id' => $orderId, 'user_id' => $this->from->id, 'mode' => 'live', 'shop_id' => 'test',
            'kind' => 'purchase', 'tier' => 'basic', 'days' => 30, 'amount_kopecks' => 12300,
            'status' => 'succeeded', 'payload' => '{}', 'processed_at' => now(), 'paid_at' => now(),
        ]);
        $paymentId = DB::table('subscription_payments')->insertGetId([
            'user_id' => $this->from->id, 'request_id' => $orderId, 'order_id' => $orderId,
            'amount_kopecks' => 12300, 'duration_days' => 30, 'paid_at' => now(),
            'starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(10),
            'recorded_by' => $this->from->id, 'canceled_by' => $this->from->id,
            'canceled_at' => now()->subDay(), 'cancel_reason' => 'test cancellation',
        ]);
        $before = (array) DB::table('subscription_payments')->find($paymentId);
        $this->assertSame(0, $this->runMerge());
        $this->assertSame($before, (array) DB::table('subscription_payments')->find($paymentId));
        $this->assertSame(0, $this->runMerge(true), Artisan::output());
        $after = (array) DB::table('subscription_payments')->find($paymentId);
        foreach (['user_id', 'recorded_by', 'canceled_by'] as $column) {
            $this->assertSame($this->to->id, $after[$column]);
            unset($before[$column], $after[$column]);
        }
        $this->assertSame($before, $after);
        $this->assertSame($this->to->id, DB::table('payment_orders')->find($orderId)->user_id);
        $this->assertSame('succeeded', DB::table('payment_orders')->find($orderId)->status);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseCount('payment_orders', 1);
        $this->assertNull($this->from->fresh());
    }

    public function test_pending_payment_alerts_follow_selected_telegram_without_duplicate_delivery(): void
    {
        $keep = $this->from->telegramAccounts()->create(['telegram_id' => 780382888]);
        $old = $this->to->telegramAccounts()->create(['telegram_id' => 1695923047]);
        $orderId = (string) \Illuminate\Support\Str::uuid();
        DB::table('payment_orders')->insert([
            'id' => $orderId, 'user_id' => $this->from->id, 'mode' => 'live', 'shop_id' => 'test',
            'kind' => 'purchase', 'tier' => 'basic', 'days' => 30, 'amount_kopecks' => 10000,
            'status' => 'succeeded', 'payload' => '{}', 'processed_at' => now(), 'paid_at' => now(),
        ]);
        foreach ([$old, $keep] as $account) {
            DB::table('payment_notifications')->insert([
                'order_id' => $orderId, 'user_id' => $account->user_id, 'channel' => 'telegram',
                'destination_id' => $account->id, 'attempts' => 0,
                'sent_at' => $account->id === $keep->id ? now() : null,
            ]);
        }
        $this->assertSame(0, Artisan::call('account:merge-portal', ['source' => $this->from->login,
            'target' => $this->to->login, '--target-id' => $this->to->id,
            '--keep-telegram-id' => 780382888, '--apply' => true]), Artisan::output());
        $this->assertDatabaseCount('payment_notifications', 2);
        $kept = DB::table('payment_notifications')->where('destination_id', $keep->id)->sole();
        $this->assertNotNull($kept->sent_at);
        $this->assertSame($this->to->id, $kept->user_id);
        $this->assertSame(10, DB::table('payment_notifications')->where('destination_id', $old->id)->sole()->attempts);
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
