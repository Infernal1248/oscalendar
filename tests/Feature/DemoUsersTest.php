<?php

namespace Tests\Feature;

use App\Models\{AirFase, GreenZone, ParserTask, PortalCredential, Role, RrjExpress, User};
use Illuminate\Support\Facades\{Artisan, DB};
use Tests\TestCase;

class DemoUsersTest extends TestCase
{
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
