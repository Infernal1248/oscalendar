<?php

namespace Tests\Feature;

use App\Models\{Deviation, GreenZone, RrjExpress, PortalProfile, Role, User};
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_every_report_scopes_rows_totals_filters_and_metadata_by_identity_and_job_role(): void
    {
        $user = User::create(['status' => 'active']);
        $permissions = collect(['deviations', 'green-zone', 'rrj-express'])
            ->flatMap(fn ($report) => ["$report.view", "$report.read", "$report.import"])->all();
        $this->grantPermissions($user, $permissions);
        PortalProfile::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'full_name' => 'Семёнов Иван Иванович', 'personnel_number' => '123456', 'synced_at' => now()]);
        foreach (['deviations' => Deviation::class, 'green-zone' => GreenZone::class, 'rrj-express' => RrjExpress::class] as $report => $model) {
            $base = array_intersect_key([
                'event_number' => '1', 'event_text' => 'Visible', 'level' => 'Low', 'parameter' => 'Own parameter',
                'flight_date' => '2026-09-15', 'aircraft_type' => 'SU95', 'aircraft_registration' => '89124',
                'flight_number' => '6001', 'flight_id' => '1', 'pilot_name' => " СЕМЕНОВ\u{00A0}ИВАН   ИВАНОВИЧ ",
                'pilot_personnel_number' => '123456', 'captain_name' => 'Other Person', 'captain_code' => '999',
                'flight_unit' => 'Отряд Север',
            ], $model::COLUMNS);
            $own = $model::forceCreate($base + ['fingerprint' => hash('sha256', 'own'), 'source_filename' => 'test.xlsx']);
            $foreign = array_replace($base, ['pilot_name' => 'Другой Пилот', 'event_text' => 'Hidden', 'level' => 'High',
                'captain_name' => 'Семёнов Иван Иванович', 'flight_unit' => 'Отряд Юг', 'parameter' => 'Hidden parameter']);
            $model::forceCreate(array_intersect_key($foreign, $model::COLUMNS) + ['fingerprint' => hash('sha256', 'crossed'), 'source_filename' => 'test.xlsx']);
            $foreign = array_replace($base, ['pilot_personnel_number' => '987654', 'captain_code' => '123456', 'flight_unit' => 'Отряд Юг']);
            $model::forceCreate($foreign + ['fingerprint' => hash('sha256', 'same-name'), 'source_filename' => 'test.xlsx']);
            $captainRow = array_replace($base, ['pilot_name' => 'Другой Пилот', 'pilot_personnel_number' => '987654',
                'captain_name' => 'семенов Иван Иванович', 'captain_code' => '123456']);
            $captain = $model::forceCreate($captainRow + ['fingerprint' => hash('sha256', 'captain'), 'source_filename' => 'test.xlsx']);
            $bothRow = array_replace($base, ['captain_name' => 'Семёнов Иван Иванович', 'captain_code' => '123456']);
            $both = $model::forceCreate($bothRow + ['fingerprint' => hash('sha256', 'both'), 'source_filename' => 'test.xlsx']);
            $this->actingAs($user);
            $this->setJob($user, null);
            $this->getJson("/api/$report")->assertOk()->assertJsonPath('total', 0);
            $this->setJob($user, 'pilot');
            $this->getJson("/api/$report?per_page=1&group_by=flight_unit")->assertOk()->assertJsonPath('total', 3)->assertJsonPath('data.0.id', $own->id);
            $visible = $this->getJson("/api/$report")->assertOk()->assertJsonCount(3, 'data')->json('data');
            $this->assertSame([$own->id, $captain->id, $both->id], array_column($visible, 'id'));
            $this->getJson("/api/$report?filters[flight_unit]=Юг")->assertOk()->assertJsonPath('total', 0);
            $this->getJson("/api/$report?".http_build_query(['filter_rules' => ['flight_unit' => [
                'operator' => 'or', 'constraints' => [['matchMode' => 'equals', 'value' => 'Отряд Юг'], ['matchMode' => 'notEquals', 'value' => 'Отряд Север']],
            ]]]))->assertOk()->assertJsonPath('total', 0);
            $metadata = $this->getJson("/api/$report/metadata")->assertOk();
            if ($model::OPTIONS) $metadata->assertJsonPath('options.event_text', ['Visible']);
            if ($model === Deviation::class) $metadata->assertJsonPath('options.level', ['Low'])->assertJsonPath('options.parameter', ['Own parameter']);

            $this->setJob($user, 'unit-head', 'Отряд Юг');
            $this->getJson("/api/$report")->assertOk()->assertJsonPath('total', 2);
            $this->setJob($user, 'unit-head');
            $this->getJson("/api/$report")->assertOk()->assertJsonPath('total', 0);
            $this->setJob($user, 'senior-leader');
            $this->getJson("/api/$report")->assertOk()->assertJsonPath('total', 5);
            $this->actingAs(User::create(['status' => 'active', 'role' => 'admin']))->getJson("/api/$report")->assertOk()->assertJsonPath('total', 5);
        }
        $noProfile = User::create(['status' => 'active']);
        $this->grantPermissions($noProfile, $permissions);
        $this->setJob($noProfile, 'pilot');
        $this->actingAs($noProfile)->getJson('/api/deviations')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/deviations/metadata')->assertJsonPath('options.event_text', []);
        $this->grantPermissions($noProfile, []);
        $this->setJob($noProfile, 'senior-leader');
        $this->getJson('/api/deviations')->assertForbidden();
    }

    private function setJob(User $user, ?string $key, ?string $unit = null): void
    {
        $user->roles()->detach(Role::whereIn('key', array_keys(Role::PILOT_ROLES))->pluck('id'));
        if ($key) $user->roles()->attach(Role::where('key', $key)->sole()->id);
        $user->forceFill(['unit_number' => $unit])->save();
        $user->unsetRelation('roles');
    }
}
