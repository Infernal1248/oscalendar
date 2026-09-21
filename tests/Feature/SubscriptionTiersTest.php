<?php

namespace Tests\Feature;

use App\Models\{Role, SubscriptionPayment, SubscriptionPrice, User};
use Illuminate\Support\Facades\{Artisan, DB};
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionTiersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }

    public function test_existing_payments_keep_dates_and_demo_access_while_tiers_follow_old_report_permissions(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_09_21'));
        Artisan::call('migrate', ['--force' => true, '--path' => $paths, '--realpath' => true]);
        $basic = User::create(['display_name' => 'Макс']);
        $extended = User::create(['display_name' => 'Андрей']);
        $demo = User::create(['login' => 'demo_premium']);
        $noPayment = User::create(['display_name' => 'Владимир']);
        $extended->roles()->attach(Role::where('key', 'airfase-reader')->sole()->id);
        foreach ([$basic, $extended, $demo] as $user) $this->grantSubscription($user);
        $before = DB::table('subscription_payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        Artisan::call('migrate', ['--force' => true]);
        foreach ([$basic->id => 'basic', $extended->id => 'extended', $demo->id => 'extended'] as $id => $tier) {
            $this->assertSame($tier, User::find($id)->subscriptionSummary()['tier']);
        }
        $after = DB::table('subscription_payments')->orderBy('id')->get()->map(function ($row) { $row = (array) $row; unset($row['tier']); return $row; })->all();
        $this->assertEquals($before, $after);
        $this->assertFalse($noPayment->hasFullAccess());
        $this->assertDatabaseMissing('roles', ['key' => 'airfase-reader']);
        $this->assertDatabaseCount('subscription_prices', 8);
    }

    public function test_tier_controls_reports_not_roles_and_expiration_revokes_access(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $user = User::create([]);
        $this->grantPermissions($user, ['airfase.read', 'airfase.import']);
        $this->grantSubscription($user);
        $payment = $user->subscriptionPayments()->sole();
        $payment->update(['tier' => 'basic']);
        $this->assertTrue($user->hasFullAccess());
        $this->assertFalse($user->hasReportsAccess());
        $this->actingAs($user)->getJson('/api/account')->assertJsonPath('subscription.tier', 'basic')->assertJsonPath('subscription.reports_access', false);
        foreach (['airfase', 'green-zone', 'rrj-express'] as $report) {
            $this->getJson('/api/'.$report)->assertStatus(402);
            $this->getJson('/api/'.$report.'/metadata')->assertStatus(402);
        }
        $this->postJson('/api/airfase/import')->assertUnprocessable();
        $payment->update(['tier' => 'extended']);
        $user->roles()->sync([]); $user->unsetRelation('roles');
        foreach (['airfase', 'green-zone', 'rrj-express'] as $report) $this->getJson('/api/'.$report)->assertOk();
        $this->assertTrue($user->hasPermission('history.view'));
        $this->postJson('/api/airfase/import')->assertForbidden();
        $payment->update(['ends_at' => now()->subDays(2)]);
        $this->getJson('/api/airfase')->assertStatus(402);
        $payment->update(['ends_at' => now()->addYear(), 'canceled_at' => now()]);
        $this->getJson('/api/airfase')->assertStatus(402);
        $admin = User::create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/airfase')->assertOk();
        $this->getJson('/api/account')->assertJsonPath('subscription.tier', 'extended');
        $this->postJson('/api/admin/roles', ['name' => 'Bypass', 'permissions' => ['airfase.read']])->assertUnprocessable();
    }

    public function test_upgrade_quote_uses_current_prices_for_all_unexpired_basic_periods_without_changing_access(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $user = User::create([]);
        $other = User::create([]);
        $this->grantSubscription($user);
        $payment = $user->subscriptionPayments()->sole();
        $payment->update(['tier' => 'basic', 'duration_days' => 365, 'amount_kopecks' => 0]);
        $future = $payment->replicate();
        $future->request_id = (string) Str::uuid();
        $future->starts_at = $payment->ends_at->copy()->addDay();
        $future->ends_at = $future->starts_at->copy()->addDays(365);
        $future->save();
        $this->grantSubscription($other);
        $before = SubscriptionPayment::all()->toArray();
        $this->actingAs($user)->getJson('/api/subscription/upgrade-quote')->assertOk()
            ->assertJsonCount(2, 'periods')->assertJsonPath('total_kopecks', 100000)
            ->assertJsonPath('periods.0.basic_kopecks', 250000)->assertJsonPath('periods.0.extended_kopecks', 300000);
        $this->assertEquals($before, SubscriptionPayment::all()->toArray());
        $this->assertFalse($user->hasReportsAccess());
        $future->update(['canceled_at' => now()]);
        $this->getJson('/api/subscription/upgrade-quote')->assertJsonCount(1, 'periods')->assertJsonPath('total_kopecks', 50000);
        $future->update(['canceled_at' => null, 'starts_at' => now()->subYears(2), 'ends_at' => now()->subYear()]);
        SubscriptionPrice::where('tier', 'extended')->where('days', 365)->update(['price_kopecks' => 310000]);
        $this->getJson('/api/subscription/upgrade-quote')->assertJsonCount(1, 'periods')->assertJsonPath('total_kopecks', 60000);
        $payment->update(['tier' => 'extended']);
        $this->getJson('/api/subscription/upgrade-quote')->assertUnprocessable();
        $user->update(['status' => 'blocked']);
        $this->getJson('/api/subscription/upgrade-quote')->assertForbidden();
    }

    public function test_history_filters_and_sorting_cover_all_pages_and_validate_columns(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $admin = User::create(['role' => 'admin']);
        foreach (['Иван', 'Пётр'] as $index => $name) {
            $user = User::create(['display_name' => $name]);
            $this->grantSubscription($user);
            $user->subscriptionPayments()->update(['amount_kopecks' => ($index + 1) * 10000, 'tier' => $index ? 'extended' : 'basic']);
        }
        $this->actingAs($admin);
        $url = '/api/admin/subscription/payments?';
        $this->getJson($url.http_build_query(['sort_by' => 'amount', 'sort_order' => 'asc', 'per_page' => 1, 'page' => 2]))
            ->assertOk()->assertJsonPath('data.0.amount_kopecks', 20000)->assertJsonPath('total', 2);
        $filter = fn ($value, $mode = 'equals') => ['operator' => 'and', 'constraints' => [['value' => $value, 'matchMode' => $mode]]];
        $this->getJson($url.http_build_query(['filter_rules' => ['amount' => $filter('150', 'gt')]]))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.user.display_name', 'Пётр');
        $this->getJson($url.http_build_query(['filter_rules' => ['user' => $filter('Иван', 'contains'), 'tier' => $filter('basic'), 'status' => $filter('confirmed')]]))
            ->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url.http_build_query(['filter_rules' => ['user' => $filter('%', 'contains')]]))->assertJsonPath('total', 0);
        $this->getJson($url.http_build_query(['filter_rules' => ['paid_at' => $filter('bad-date')]]))->assertUnprocessable();
        $this->getJson($url.'sort_by=invalid')->assertUnprocessable();
        $this->getJson($url.http_build_query(['filter_rules' => ['invalid' => $filter('1')]]))->assertUnprocessable();
    }

    public function test_admin_prices_and_global_history_are_private_and_payments_remain_unchanged(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $admin = User::create(['role' => 'admin']);
        $user = User::create(['display_name' => 'Оплата Тест']);
        $price = SubscriptionPrice::where('tier', 'basic')->where('days', 30)->sole();
        $this->actingAs($user)->getJson('/api/subscription/prices')->assertOk()->assertJsonCount(8);
        $this->getJson('/api/admin/subscription/payments')->assertForbidden();
        $this->patchJson('/api/admin/subscription/prices/'.$price->id, ['price_kopecks' => 1, 'old_price_kopecks' => 1])->assertForbidden();
        $this->actingAs($admin);
        foreach (['basic', 'extended'] as $tier) {
            $target = User::create(['display_name' => 'Оплата '.$tier]);
            $body = ['request_id' => (string) Str::uuid(), 'tier' => $tier, 'amount' => '0', 'paid_at' => now()->toDateString(), 'duration_days' => 30];
            $url = '/api/admin/users/'.$target->id.'/subscription/payments';
            $this->postJson($url, $body)->assertOk()->assertJsonPath('subscription.tier', $tier);
            $this->postJson($url, $body)->assertOk();
            $this->postJson($url, array_replace($body, ['tier' => $tier === 'basic' ? 'extended' : 'basic']))->assertConflict();
            $this->postJson($url, array_replace($body, ['request_id' => (string) Str::uuid(), 'tier' => $tier === 'basic' ? 'extended' : 'basic']))->assertUnprocessable();
        }
        $this->getJson('/api/admin/subscription/payments?search='.urlencode('Оплата'))->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/subscription/payments?search=%25')->assertJsonPath('total', 0);
        $snapshot = SubscriptionPayment::all()->toArray();
        $this->patchJson('/api/admin/subscription/prices/'.$price->id, ['price_kopecks' => 26000, 'old_price_kopecks' => 25000])->assertUnprocessable();
        $this->patchJson('/api/admin/subscription/prices/'.$price->id, ['price_kopecks' => 24000, 'old_price_kopecks' => 25000])->assertOk();
        $this->assertEquals($snapshot, SubscriptionPayment::all()->toArray());
        $this->actingAs($user)->getJson('/api/subscription/prices')->assertJsonFragment(['price_kopecks' => 24000]);
    }
}
