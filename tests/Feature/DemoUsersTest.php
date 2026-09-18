<?php

namespace Tests\Feature;

use App\Models\{AirFase, GreenZone, ParserTask, PortalCredential, Role, RrjExpress, User};
use Illuminate\Support\Facades\{Artisan, DB};
use Tests\TestCase;

class DemoUsersTest extends TestCase
{
    public function test_demo_reports_are_visible_only_to_owner_and_admin_including_filter_options(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame(1, Artisan::call('demo:reports'));
        Artisan::call('demo:create');
        $demo = User::where('login', 'demo_premium')->sole();
        $admin = User::create(['role' => 'admin', 'status' => 'active']);
        $others = [User::where('login', 'demo_basic')->sole()];
        foreach (['pilot', 'unit-head', 'senior-leader'] as $position) {
            $user = User::create(['status' => 'active']);
            $user->roles()->sync([Role::where('key', 'demo-viewer')->sole()->id, Role::where('key', $position)->sole()->id]);
            $user->forceFill(['unit_number' => 'Демонстрационный отряд'])->save();
            \App\Models\PortalProfile::create(['user_id' => $user->id, 'source' => 'test',
                'full_name' => $demo->portalProfile->full_name, 'personnel_number' => $demo->portalProfile->personnel_number, 'synced_at' => now()]);
            $others[] = $user;
        }
        foreach ($others as $user) $this->grantSubscription($user);
        foreach ([AirFase::class, RrjExpress::class, GreenZone::class] as $model) \App\Services\ReportFilterOptions::values($model);
        $this->assertSame(0, Artisan::call('demo:reports'));
        $this->assertSame(0, Artisan::call('demo:reports'));
        foreach (['airfase' => AirFase::class, 'rrj-express' => RrjExpress::class, 'green-zone' => GreenZone::class] as $report => $model) {
            $this->assertSame(10, $model::count());
            // One ordinary row remains available to leaders and is not overwritten by the command.
            $row = $model::first()->getAttributes();
            unset($row['id']);
            $row['demo_user_id'] = null;
            $row['fingerprint'] = hash('sha256', 'ordinary-'.$model);
            $row['flight_unit'] = 'Обычный отряд';
            $row['pilot_personnel_number'] = $row['captain_code'] = '123456';
            DB::table((new $model)->getTable())->insert($row);
            \App\Services\ReportFilterOptions::invalidate($model);
            foreach ([$demo, $admin] as $user) {
                $this->actingAs($user)->getJson('/api/'.$report)->assertOk()->assertJsonPath('total', $user->isAdmin() ? 11 : 10);
                $this->assertContains('Демонстрационный отряд', \App\Services\ReportFilterOptions::values($model, $user)['flight_unit']);
            }
            foreach ($others as $user) {
                $this->actingAs($user)->getJson('/api/'.$report)->assertOk()->assertJsonPath('total', $user->pilotRole() === 'senior-leader' ? 1 : 0);
                $this->assertNotContains('Демонстрационный отряд', \App\Services\ReportFilterOptions::values($model, $user)['flight_unit']);
                $this->actingAs($user)->getJson('/api/'.$report.'?filters[flight_unit]='.urlencode('Демонстрационный отряд'))
                    ->assertOk()->assertJsonPath('total', 0);
            }
            $this->assertSame(['Обычный отряд'], \App\Services\ReportFilterOptions::values($model)['flight_unit']);
        }
        $this->assertNotContains('Демонстрационный отряд', \App\Services\FlightUnitDirectory::values());
    }

    public function test_demo_accounts_have_fake_private_plans_and_read_only_access_without_portal_jobs(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        // Even a customized default role must not grant administrative access to demos.
        Role::where('key', 'user')->update(['permissions' => json_encode(['users.manage', 'airfase.import'])]);
        $real = User::create(['display_name' => 'Existing user']);
        foreach ([AirFase::class, GreenZone::class, RrjExpress::class] as $model) {
            $fields = ['flight_date' => now()->toDateString(), 'flight_number' => '123', 'aircraft_registration' => '12345',
                'pilot_name' => 'Existing Pilot', 'pilot_personnel_number' => '123456',
                'fingerprint' => hash('sha256', $model), 'source_filename' => 'existing.xlsx'];
            if ($model === GreenZone::class) $fields['flight_id'] = '123';
            else $fields += ['event_number' => '1004', 'event_text' => 'Existing event'];
            if ($model === AirFase::class) $fields += ['level' => 'Low', 'aircraft_type' => 'RRJ'];
            DB::table((new $model)->getTable())->insert($fields);
        }
        $this->assertSame(0, Artisan::call('demo:create'));
        $output = Artisan::output();
        foreach (['basic', 'premium'] as $plan) {
            $user = User::where('login', 'demo_'.$plan)->sole();
            $this->assertStringContainsString($user->login, $output);
            preg_match('/'.preg_quote($user->login, '/').'\s*\|\s*(\w+)/', $output, $matches);
            $this->assertTrue(\Illuminate\Support\Facades\Hash::check($matches[1], $user->password));
            $this->assertSame('pilot', $user->pilotRole());
            $this->assertFalse($user->isAdmin());
            $this->assertFalse($user->hasPermission('users.manage'));
            $this->assertFalse($user->hasPermission('airfase.import'));
            $this->assertSame($plan === 'premium', $user->hasFullAccess());
            $this->assertSame('demo', $user->portalProfile->source);
            $this->assertCount(7, $user->rosterItems);
            $this->assertCount(3, $user->rosterChangeEvents);
            $this->assertFalse($user->rosterChangeEvents()->whereIn('status', ['pending', 'acknowledgement_requested'])->exists());
            foreach (['airfase' => AirFase::class, 'green-zone' => GreenZone::class, 'rrj-express' => RrjExpress::class] as $report => $model) {
                $this->assertTrue($user->hasPermission($report.'.read'));
                $this->assertSame(0, $model::visibleTo($user)->count());
                $this->assertSame(1, $model::count(), 'Existing report data must remain untouched');
                $response = $this->actingAs($user)->getJson('/api/'.$report);
                if ($plan === 'premium') $response->assertOk()->assertJsonPath('total', 0);
                else $response->assertStatus(402);
            }
            $this->actingAs($user)->getJson('/api/account')->assertOk()->assertJsonPath('subscription.full_access', $plan === 'premium');
            $this->getJson('/api/workplan')->assertOk()->assertJsonCount(7);
            $this->getJson('/api/change-history')->assertOk()->assertJsonCount(3);
        }
        $this->assertSame(0, PortalCredential::count());
        $this->assertSame(0, ParserTask::count());
        $this->assertSame('Existing user', $real->fresh()->display_name);
        $this->assertSame(1, Artisan::call('demo:create'));
        $this->assertSame(3, User::count());
    }
}
