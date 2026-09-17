<?php

namespace Tests\Feature;

use App\Models\{CalendarFeed, RosterChangeEvent, Role, SubscriptionPayment, User};
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Carbon::setTestNow('2026-09-17 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payment(array $replace = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'amount' => '199,90',
            'duration_days' => 30, 'paid_at' => now()->toDateString(), 'comment' => 'Private admin note'], $replace);
    }

    public function test_mysql_migration_uses_explicit_datetime_columns_for_payment_dates(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
        $connection = new \Illuminate\Database\MySqlConnection(
            fn () => throw new \LogicException('SQL compilation must not connect to a database.'), 'schema_test'
        );
        try {
            \Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());
            $queries = $connection->pretend(function () {
                $migration = require database_path('migrations/2026_09_17_120000_create_subscription_payments_table.php');
                $migration->up();
            });
            $sql = implode("\n", array_column($queries, 'query'));
            foreach (['paid_at', 'starts_at', 'ends_at'] as $field) {
                $this->assertStringContainsString("`$field` datetime not null", $sql);
            }
            foreach (['requested_starts_at', 'canceled_at'] as $field) {
                $this->assertStringContainsString("`$field` datetime null", $sql);
            }
            $this->assertStringNotContainsString('timestamp not null', $sql);
        } finally {
            \Illuminate\Support\Facades\Schema::swap($schema);
        }
    }

    public function test_manual_payments_extend_once_and_cancellation_preserves_audit_and_other_periods(): void
    {
        $admin = User::create(['role' => 'admin']);
        $user = User::create([]);
        $url = "/api/admin/users/{$user->id}/subscription/payments";
        $this->actingAs($admin);
        $payload = $this->payment();
        $first = $this->postJson($url, $payload)->assertOk()->assertJsonPath('subscription.full_access', true)
            ->assertJsonPath('payments.data.0.amount_kopecks', 19990)->json('payments.data.0');
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->postJson($url, array_replace($payload, ['amount' => '200']))->assertConflict();
        $second = $this->postJson($url, $this->payment(['duration_days' => 90]))->assertOk()->json('payments.data.0');
        $this->assertSame(Carbon::parse($first['ends_at'])->addDay()->toDateString(), $second['starts_at']);
        $this->assertSame(now()->addDays(121)->toDateString(), $second['ends_at']);
        $this->assertSame($second['ends_at'], $user->subscriptionSummary()['paid_until']);
        $this->assertTrue($user->hasFullAccess());
        $this->postJson($url.'/'.$first['id'].'/cancel', ['reason' => 'Ошибочная запись'])->assertOk();
        $this->postJson($url.'/'.$first['id'].'/cancel', ['reason' => 'Повтор'])->assertOk();
        $this->assertFalse($user->hasFullAccess());
        $this->assertSame('Ошибочная запись', SubscriptionPayment::find($first['id'])->cancel_reason);
        $this->assertSame($admin->id, SubscriptionPayment::find($first['id'])->canceled_by);
        $this->assertSame($second['starts_at'], SubscriptionPayment::find($second['id'])->starts_at->toDateString());
        $this->assertDatabaseCount('subscription_payments', 2);
        Carbon::setTestNow(Carbon::parse($second['starts_at']));
        $this->assertTrue($user->hasFullAccess());
        Carbon::setTestNow(Carbon::parse($second['ends_at'])->endOfDay());
        $this->assertTrue($user->hasFullAccess());
        Carbon::setTestNow(Carbon::parse($second['ends_at'])->addDay());
        $this->assertFalse($user->hasFullAccess());
    }

    public function test_admin_can_grant_free_premium_with_a_zero_amount_and_cancel_it(): void
    {
        $admin = User::create(['role' => 'admin']);
        $user = User::create([]);
        $url = "/api/admin/users/{$user->id}/subscription/payments";
        $payload = $this->payment(['amount' => '0', 'duration_days' => 365, 'comment' => 'Подарок']);
        $payment = $this->actingAs($admin)->postJson($url, $payload)->assertOk()
            ->assertJsonPath('subscription.full_access', true)
            ->assertJsonPath('subscription.paid_until', '2027-09-17')
            ->assertJsonPath('payments.data.0.amount_kopecks', 0)
            ->assertJsonPath('payments.data.0.recorded_by', $admin->id)
            ->assertJsonPath('payments.data.0.comment', 'Подарок')->json('payments.data.0');
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->actingAs($user)->getJson('/api/subscription')->assertOk()
            ->assertJsonPath('subscription.full_access', true)
            ->assertJsonPath('payments.data.0.amount_kopecks', 0);
        $this->actingAs($admin)->postJson($url.'/'.$payment['id'].'/cancel', ['reason' => 'Ошибка'])
            ->assertOk()->assertJsonPath('subscription.full_access', false);
    }

    public function test_payment_date_sets_the_start_day_without_a_timezone_shift(): void
    {
        Carbon::setTestNow('2026-09-17 20:00:00');
        $admin = User::create(['role' => 'admin', 'timezone' => 'Asia/Krasnoyarsk']);
        $user = User::create(['timezone' => 'America/Los_Angeles']);
        $url = "/api/admin/users/{$user->id}/subscription/payments";
        $this->actingAs($admin)->postJson($url, $this->payment(['paid_at' => '2026-09-01']))
            ->assertOk()->assertJsonPath('payments.data.0.paid_at', '2026-09-01')
            ->assertJsonPath('payments.data.0.starts_at', '2026-09-01')
            ->assertJsonPath('payments.data.0.ends_at', '2026-10-01');
        $this->getJson('/api/admin/users/'.$user->id.'/subscription')
            ->assertJsonPath('payments.data.0.paid_at', '2026-09-01');
        $this->actingAs($user)->getJson('/api/subscription')->assertJsonPath('payments.data.0.paid_at', '2026-09-01');
        // The administrator's calendar date is already September 18, unlike UTC.
        $this->actingAs($admin)->postJson($url, $this->payment(['paid_at' => '2026-09-18']))
            ->assertOk()->assertJsonPath('payments.data.0.paid_at', '2026-09-18');
        $this->postJson($url, $this->payment(['paid_at' => '2026-09-19']))->assertUnprocessable();
    }

    public function test_year_subscription_includes_both_dates_and_legacy_times_do_not_cut_the_last_day_short(): void
    {
        $admin = User::create(['role' => 'admin']);
        $user = User::create([]);
        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/subscription/payments", $this->payment(['duration_days' => 365]))
            ->assertOk()->assertJsonPath('payments.data.0.starts_at', '2026-09-17')
            ->assertJsonPath('payments.data.0.ends_at', '2027-09-17')
            ->assertJsonPath('subscription.paid_until', '2027-09-17')
            ->assertJsonPath('subscription.extension_from', '2027-09-18');
        // Simulate a payment saved before calendar-day accounting was introduced.
        DB::table('subscription_payments')->where('user_id', $user->id)->update([
            'starts_at' => '2026-09-17 11:58:09', 'ends_at' => '2027-09-17 11:58:09',
        ]);
        foreach (['2026-09-16 23:59:59' => false, '2026-09-17 00:00:00' => true,
            '2027-09-17 23:59:59' => true, '2027-09-18 00:00:00' => false] as $time => $expected) {
            Carbon::setTestNow($time);
            $this->assertSame($expected, $user->hasFullAccess(), $time);
            $this->assertSame($expected, $user->subscriptionSummary()['full_access'], $time);
        }
        $this->actingAs($user)->getJson('/api/subscription')->assertJsonPath('payments.data.0.starts_at', '2026-09-17')
            ->assertJsonPath('payments.data.0.ends_at', '2027-09-17');
    }

    public function test_only_admin_can_record_or_cancel_payments_and_users_only_see_their_history(): void
    {
        $admin = User::create(['role' => 'admin']);
        $user = User::create([]);
        $other = $this->grantPermissions(User::create([]), ['users.view', 'users.manage']);
        $url = "/api/admin/users/{$user->id}/subscription";
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->postJson($url.'/payments', $this->payment())->assertForbidden();
        $payment = $this->actingAs($admin)->postJson($url.'/payments', $this->payment())->assertOk()->json('payments.data.0');
        $this->actingAs($other)->postJson($url.'/payments/'.$payment['id'].'/cancel', ['reason' => 'no'])->assertForbidden();
        $this->getJson('/api/subscription')->assertOk()->assertJsonPath('payments.total', 0)->assertJsonPath('subscription.full_access', false);
        $this->actingAs($user)->getJson('/api/subscription')->assertJsonPath('payments.total', 1)
            ->assertJsonMissingPath('payments.data.0.comment')->assertJsonMissingPath('payments.data.0.recorded_by');
        $this->actingAs($admin)->postJson("/api/admin/users/{$other->id}/subscription/payments/{$payment['id']}/cancel", ['reason' => 'wrong user'])->assertNotFound();
        foreach ([['amount' => ''], ['amount' => null], ['amount' => '-1'], ['amount' => '1.123'], ['duration_days' => 31],
            ['paid_at' => now()->addDay()->toDateString()], ['paid_at' => now()->toIso8601String()],
            ['starts_at' => now()->subDay()->toIso8601String()]] as $invalid) {
            $this->postJson($url.'/payments', $this->payment($invalid))->assertUnprocessable();
        }
        $this->postJson($url.'/payments/'.$payment['id'].'/cancel', [])->assertUnprocessable();
    }

    public function test_basic_access_never_returns_paid_report_data_history_details_or_calendar_tokens(): void
    {
        $user = $this->grantPermissions(User::create([]), [
            'profile.view', 'workplan.view', 'history.view',
            'deviations.view', 'deviations.read', 'deviations.import',
            'green-zone.view', 'green-zone.read', 'rrj-express.view', 'rrj-express.read',
        ]);
        $feed = CalendarFeed::create(['user_id' => $user->id, 'token' => 'PRIVATE_CALENDAR_TOKEN', 'is_active' => true]);
        $event = RosterChangeEvent::create([
            'user_id' => $user->id, 'source' => 'rossiya_edu', 'period' => '2026-09', 'status' => 'pending',
            'change_hash' => str_repeat('a', 64), 'changes' => [['secret' => 'PRIVATE_CHANGE_DETAILS']],
        ]);
        $this->actingAs($user)->getJson('/api/account')->assertOk()->assertJsonPath('calendar_url', null)
            ->assertJsonPath('subscription.full_access', false)->assertDontSee($feed->token);
        $this->getJson('/api/change-history')->assertOk()->assertJsonPath('0.changes', [])
            ->assertJsonPath('0.details_locked', true)->assertDontSee('PRIVATE_CHANGE_DETAILS');
        $this->getJson('/api/workplan')->assertOk();
        $this->postJson('/api/change-history/'.$event->id.'/acknowledge')->assertStatus(202);
        foreach (['deviations', 'green-zone', 'rrj-express'] as $report) {
            $this->getJson('/api/'.$report)->assertStatus(402);
            $this->getJson('/api/'.$report.'/metadata')->assertStatus(402);
        }
        $this->postJson('/api/deviations/import', [])->assertUnprocessable(); // Import permission stays independent.
        $this->get('/api/calendar/'.$feed->token.'.ics')->assertForbidden();
        $admin = User::create(['role' => 'admin']);
        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/subscription/payments", $this->payment())->assertOk();
        $this->actingAs($user)->getJson('/api/account')->assertJsonPath('subscription.full_access', true)->assertSee($feed->token);
        $this->getJson('/api/change-history')->assertJsonPath('0.details_locked', false)->assertSee('PRIVATE_CHANGE_DETAILS');
        $this->get('/api/calendar/'.$feed->token.'.ics')->assertOk();
        foreach (['deviations', 'green-zone', 'rrj-express'] as $report) $this->getJson('/api/'.$report)->assertOk()->assertJsonPath('total', 0);
        $this->grantPermissions($user, ['profile.view']);
        $this->getJson('/api/deviations')->assertForbidden(); // Payment does not grant permissions.
        $user->update(['status' => 'blocked']);
        $this->assertFalse($user->fresh()->hasFullAccess());
        $this->get('/api/calendar/'.$feed->token.'.ics')->assertForbidden();
    }

    public function test_preview_copy_follows_job_role_without_exposing_the_role_key(): void
    {
        $user = User::create([]);
        foreach (['pilot' => 'ваши записи', 'unit-head' => 'вашему лётному отряду', 'senior-leader' => 'все записи'] as $key => $copy) {
            $user->roles()->sync([Role::where('key', $key)->sole()->id]);
            $user->unsetRelation('roles');
            $response = $this->actingAs($user)->getJson('/api/account')->assertOk()->assertJsonMissingPath('pilot_role');
            $this->assertStringContainsString($copy, $response->json('report_preview'));
        }
    }

    public function test_user_list_shows_current_subscription_end_without_payment_details_or_per_user_queries(): void
    {
        $admin = User::create(['role' => 'admin']);
        $viewer = $this->grantPermissions(User::create([]), ['users.view']);
        $premium = User::create([]);
        $url = "/api/admin/users/{$premium->id}/subscription/payments";
        $this->actingAs($admin)->postJson($url, $this->payment())->assertOk();
        $this->postJson($url, $this->payment())->assertOk();
        $expected = $premium->subscriptionSummary()['paid_until'];
        $cases = [
            'expired' => ['starts_at' => '2026-08-01', 'ends_at' => '2026-09-16'],
            'future' => ['starts_at' => '2026-09-18', 'ends_at' => '2026-10-18'],
            'canceled' => ['starts_at' => '2026-09-01', 'ends_at' => '2026-10-01', 'canceled_at' => now()],
        ];
        foreach ($cases as $name => $dates) {
            $user = User::create(['display_name' => $name]);
            $user->subscriptionPayments()->create($dates + ['request_id' => (string) Str::uuid(),
                'amount_kopecks' => 100, 'duration_days' => 30, 'paid_at' => now(), 'recorded_by' => $admin->id]);
        }
        foreach ([$admin, $viewer] as $actor) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->actingAs($actor)->getJson('/api/admin/users')->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query['query'], 'subscription_payments')));
            foreach ($response->json() as $row) {
                $this->assertSame($row['id'] === $premium->id ? $expected : null, $row['subscription_paid_until']);
                $this->assertArrayNotHasKey('subscription_payments', $row);
                $this->assertArrayNotHasKey('amount_kopecks', $row);
            }
        }
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$premium->id, ['status' => 'active'])
            ->assertOk()->assertJsonPath('subscription_paid_until', $expected);
        $this->actingAs($viewer)->getJson('/api/admin/users/'.$premium->id.'/subscription')->assertForbidden();
        $this->actingAs($premium)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_telegram_calendar_command_obeys_the_same_subscription(): void
    {
        config(['services.telegram_bot.token' => 'test-bot-token']);
        \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
        $user = User::create(['display_name' => 'Test']);
        \App\Models\TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 123456]);
        $message = ['message' => ['chat' => ['id' => 123456], 'from' => ['id' => 123456], 'text' => 'Мой календарь']];
        app(\App\Services\Telegram\TelegramBotService::class)->handle($message);
        $this->assertDatabaseCount('calendar_feeds', 0);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Календарь доступен в полной версии'));
        $this->grantSubscription($user);
        app(\App\Services\Telegram\TelegramBotService::class)->handle($message);
        $this->assertDatabaseCount('calendar_feeds', 1);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', '/api/calendar/'));
    }
}
