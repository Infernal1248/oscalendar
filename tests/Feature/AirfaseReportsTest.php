<?php

namespace Tests\Feature;

use App\Models\GreenZone;
use App\Models\RrjExpress;
use App\Models\User;
use App\Services\ReportFilterOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AirfaseReportsTest extends TestCase
{
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) unlink($path);
        parent::tearDown();
    }

    public function test_separate_reports_have_opt_in_access_deduplication_metadata_and_server_queries(): void
    {
        $user = User::create(['status' => 'active']);
        $this->actingAs($user);
        foreach (['green-zone' => GreenZone::class, 'rrj-express' => RrjExpress::class] as $report => $model) {
            $this->grantPermissions($user, ['deviations.view', 'deviations.read', 'deviations.import']);
            $this->getJson("/api/$report")->assertForbidden();
            $this->getJson("/api/$report/metadata")->assertForbidden();
            $this->postJson("/api/$report/import")->assertForbidden();
            $this->grantPermissions($user, ["$report.view", "$report.import"]);
            $this->getJson("/api/$report")->assertForbidden();
            $first = $this->row($model);
            $first['flight_unit'] = 'Отряд Север';
            $second = array_replace($first, ['flight_number' => '6002', 'flight_date' => '2026-08-02']);
            if ($model === GreenZone::class) $second['max_pitch'] = '10';
            $this->assertSame([], ReportFilterOptions::values($model)['flight_unit']);
            $this->postJson("/api/$report/import", ['file' => $this->file($model, [$first, $second])])->assertOk()
                ->assertExactJson(['processed' => 2, 'inserted' => 2, 'duplicates' => 0]);
            $this->assertSame([trim($first['flight_unit'])], ReportFilterOptions::values($model)['flight_unit']);
            if ($model === RrjExpress::class) $first['report_event_count'] = '999';
            $this->postJson("/api/$report/import", ['file' => $this->file($model, [$first])])->assertOk()
                ->assertJsonPath('inserted', 0)->assertJsonPath('duplicates', 1);
            $this->grantPermissions($user, ["$report.view", "$report.read"]);
            $user->roles()->attach(\App\Models\Role::where('key', 'senior-leader')->sole()->id);
            $this->postJson("/api/$report/import")->assertForbidden();
            $this->getJson("/api/$report/metadata")->assertOk()->assertJsonCount(count($model::COLUMNS), 'columns');
            if ($model === RrjExpress::class) {
                $this->getJson("/api/$report/metadata")->assertJsonPath('options.event_text.0', trim($first['event_text']));
            }
            $this->getJson("/api/$report?per_page=1&sort_by=flight_date&sort_order=asc&group_by=flight_number")
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2)
                ->assertJsonMissingPath('data.0.fingerprint')->assertJsonMissingPath('data.0.uploaded_by');
            $this->getJson("/api/$report?per_page=501")->assertUnprocessable();
            $this->getJson("/api/$report?filters[unknown]=1")->assertUnprocessable();
            $this->getJson("/api/$report?sort_by=fingerprint")->assertUnprocessable();
            $this->getJson("/api/$report?".http_build_query(['filter_rules' => ['flight_number' => [
                'operator' => 'and', 'constraints' => [['matchMode' => 'equals', 'value' => '6001']],
            ]]]))->assertOk()->assertJsonPath('total', 1);
            if ($model === GreenZone::class) {
                $this->getJson("/api/$report?sort_by=max_pitch&sort_order=asc")->assertOk()->assertJsonPath('data.0.flight_number', '6001');
                $this->getJson("/api/$report?".http_build_query(['filter_rules' => ['max_pitch' => [
                    'operator' => 'and', 'constraints' => [['matchMode' => 'gt', 'value' => '3']],
                ]]]))->assertOk()->assertJsonPath('total', 1);
            }
            $this->grantPermissions($user, ["$report.view", "$report.import"]);
            $invalid = array_replace($first, ['flight_date' => 'bad date']);
            $this->postJson("/api/$report/import", ['file' => $this->file($model, [$second, $invalid])])->assertUnprocessable();
            $this->assertSame(2, $model::count());
        }
        $this->assertDatabaseCount('deviations', 0);
        $this->assertDatabaseCount('rrj_express_events', 2);
        $this->assertDatabaseCount('green_zone_flights', 2);
    }

    public function test_import_invalidates_only_its_report_options_and_union_uses_those_caches(): void
    {
        $this->actingAs(User::create(['role' => 'admin', 'status' => 'active']));
        $models = [\App\Models\Deviation::class, GreenZone::class, RrjExpress::class];
        foreach ($models as $model) {
            $this->assertSame(array_fill_keys($model::OPTIONS, []), ReportFilterOptions::values($model));
        }
        $row = array_replace($this->row(GreenZone::class), [
            'aircraft_type' => 'SU95', 'pilot_position' => 'КВС', 'flight_unit' => 'Отряд Север',
        ]);
        $this->postJson('/api/green-zone/import', ['file' => $this->file(GreenZone::class, [$row])])
            ->assertOk()->assertJsonPath('inserted', 1);
        DB::enableQueryLog();
        foreach ($models as $model) ReportFilterOptions::values($model);
        $this->assertCount(count(GreenZone::OPTIONS), DB::getQueryLog());
        foreach (DB::getQueryLog() as $query) $this->assertStringContainsString('green_zone_flights', $query['query']);
        DB::flushQueryLog();
        $this->assertSame(['Отряд Север'], \App\Services\FlightUnitDirectory::values());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->getJson('/api/green-zone/metadata')->assertJsonPath('options', [
            'aircraft_type' => ['SU95'], 'pilot_position' => ['КВС'], 'flight_unit' => ['Отряд Север'],
        ]);

        // Duplicate-only and rejected imports leave the warm cache alone.
        foreach ([$row, array_replace($row, ['flight_date' => 'invalid'])] as $candidate) {
            $response = $this->postJson('/api/green-zone/import', ['file' => $this->file(GreenZone::class, [$candidate])]);
            if ($candidate === $row) $response->assertOk()->assertJsonPath('inserted', 0);
            else $response->assertUnprocessable();
            DB::enableQueryLog();
            DB::flushQueryLog();
            ReportFilterOptions::values(GreenZone::class);
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
        }

        $row = array_replace($this->row(RrjExpress::class), ['pilot_position' => 'ВП', 'flight_unit' => 'Отряд Юг']);
        $this->postJson('/api/rrj-express/import', ['file' => $this->file(RrjExpress::class, [$row])])->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame(['Отряд Север', 'Отряд Юг'], \App\Services\FlightUnitDirectory::values());
        $this->assertCount(count(RrjExpress::OPTIONS), DB::getQueryLog());
        foreach (DB::getQueryLog() as $query) $this->assertStringContainsString('rrj_express_events', $query['query']);
        DB::disableQueryLog();
    }

    public function test_green_zone_rounds_numbers_before_validation_and_deduplication(): void
    {
        $this->actingAs(User::create(['role' => 'admin', 'status' => 'active']));
        $row = array_replace($this->row(GreenZone::class), array_fill_keys(GreenZone::DECIMALS, '1.1234567891'));
        $row['takeoff_pitch'] = '11.1599998474121';
        $row['max_roll'] = '-30,1234567891';
        $row['max_load'] = '1.305';
        $row['landing_load'] = '-1.305';
        $row['threshold_distance'] = null;
        $row['threshold_time'] = '1e-8';
        $this->postJson('/api/green-zone/import', ['file' => $this->file(GreenZone::class, [$row])])
            ->assertOk()->assertJsonPath('inserted', 1);
        $saved = GreenZone::firstOrFail();
        $rounded = array_replace($row, array_fill_keys(GreenZone::DECIMALS, '1.12'), [
            'takeoff_pitch' => '11.16', 'max_roll' => '-30.12', 'max_load' => '1.31',
            'landing_load' => '-1.31', 'threshold_distance' => null, 'threshold_time' => '0',
        ]);
        foreach (GreenZone::DECIMALS as $field) {
            $this->assertSame($rounded[$field], $saved->$field === null ? null : (string) $saved->$field);
        }
        foreach ([$row, $rounded] as $duplicate) {
            $this->postJson('/api/green-zone/import', ['file' => $this->file(GreenZone::class, [$duplicate])])
                ->assertOk()->assertJsonPath('inserted', 0)->assertJsonPath('duplicates', 1);
        }

        foreach (['not a number', '1e1000', '10000000000000'] as $invalid) {
            $row['takeoff_pitch'] = $invalid;
            $this->postJson('/api/green-zone/import', ['file' => $this->file(GreenZone::class, [$row])])
                ->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('green_zone_flights', 1);
    }

    public function test_green_zone_reimport_matches_legacy_precision_without_changing_existing_data(): void
    {
        $this->actingAs(User::create(['role' => 'admin', 'status' => 'active']));
        $row = array_replace($this->row(GreenZone::class), ['max_load' => '1.305']);
        $old = array_replace($row, ['max_pitch' => '2']);
        $legacy = GreenZone::forceCreate($old + [
            'fingerprint' => hash('sha256', json_encode($old, JSON_UNESCAPED_UNICODE)),
            'source_filename' => 'previous.xlsx',
        ]);
        foreach (['1.305', '1.31', '1.3049999999999999'] as $value) {
            $row['max_load'] = $value;
            $this->postJson('/api/green-zone/import', ['file' => $this->file(GreenZone::class, [$row])])
                ->assertOk()->assertJsonPath('inserted', 0)->assertJsonPath('duplicates', 1);
        }
        $this->assertSame('1.305', (string) $legacy->fresh()->max_load);
        $this->assertDatabaseCount('green_zone_flights', 1);
    }

    public function test_wrong_report_format_and_formulas_are_rejected(): void
    {
        $this->actingAs(User::create(['role' => 'admin', 'status' => 'active']));
        $this->postJson('/api/green-zone/import', ['file' => $this->file(RrjExpress::class, [$this->row(RrjExpress::class)])])->assertUnprocessable();
        $this->postJson('/api/rrj-express/import', ['file' => $this->file(GreenZone::class, [$this->row(GreenZone::class)])])->assertUnprocessable();
        $row = array_replace($this->row(GreenZone::class), ['max_pitch' => '=1+2']);
        $this->postJson('/api/green-zone/import', ['file' => $this->file(GreenZone::class, [$row])])->assertUnprocessable();
        $this->assertDatabaseCount('green_zone_flights', 0);
    }

    public function test_actual_samples_reimport_without_duplicates(): void
    {
        if (! getenv('RRJ_SAMPLE') || ! getenv('GREEN_SAMPLE')) $this->markTestSkipped('Set RRJ_SAMPLE and GREEN_SAMPLE for local reports.');
        $this->actingAs(User::create(['role' => 'admin', 'status' => 'active']));
        foreach (['rrj-express' => getenv('RRJ_SAMPLE'), 'green-zone' => getenv('GREEN_SAMPLE')] as $report => $path) {
            $first = $this->postJson("/api/$report/import", ['file' => new UploadedFile($path, basename($path), null, null, true)])->assertOk()->json();
            $this->assertGreaterThan(300, $first['inserted']);
            $this->postJson("/api/$report/import", ['file' => new UploadedFile($path, basename($path), null, null, true)])->assertOk()
                ->assertJsonPath('inserted', 0)->assertJsonPath('duplicates', $first['processed']);
            $this->getJson("/api/$report?per_page=500")->assertOk()->assertJsonCount(min(500, $first['inserted']), 'data');
        }
    }

    private function row(string $model): array
    {
        return array_replace(array_fill_keys(array_keys($model::COLUMNS), null), array_intersect_key([
            'event_number' => '1105', 'event_text' => str_repeat('Событие ', 40), 'report_event_count' => '2',
            'flight_date' => '2026-08-01', 'flight_number' => '6001', 'aircraft_registration' => '89124',
            'flight_id' => '180001', 'max_pitch' => '2.00', 'max_roll' => '-30.76', 'duration' => '00:00:37.3750000',
        ], $model::COLUMNS));
    }

    private function file(string $model, array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([array_values($model::COLUMNS), ...array_map('array_values', $rows)]);
        $path = tempnam(sys_get_temp_dir(), 'airfase-test-');
        $this->files[] = $path;
        $writer = $model === RrjExpress::class ? new Xls($book) : new Xlsx($book);
        $writer->setPreCalculateFormulas(false)->save($path);
        $book->disconnectWorksheets();
        return new UploadedFile($path, $model === RrjExpress::class ? 'report.xlsx.xls' : 'report.xlsx', null, null, true);
    }
}
