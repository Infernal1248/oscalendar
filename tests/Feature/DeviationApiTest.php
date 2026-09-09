<?php

namespace Tests\Feature;

use App\Models\Deviation;
use App\Models\User;
use App\Services\DeviationImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DeviationApiTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_permissions_are_opt_in_independent_and_only_admin_can_assign_them(): void
    {
        $this->getJson('/api/deviations')->assertUnauthorized();
        $user = User::create(['display_name' => 'Reader', 'status' => 'active']);
        $this->actingAs($user)->getJson('/api/account')
            ->assertJsonMissingPath('permissions')->assertJsonMissingPath('role')
            ->assertJsonPath('deviations_access.read', false)->assertJsonPath('deviations_access.import', false);
        $this->getJson('/api/deviations')->assertForbidden();
        $this->postJson('/api/deviations/import')->assertForbidden();

        $this->grantPermissions($user, ['deviations.view']);
        $this->getJson('/api/account')->assertJsonFragment(['navigation' => ['profile', 'deviations']]);
        $this->getJson('/api/deviations')->assertForbidden();
        $this->postJson('/api/deviations/import')->assertForbidden();
        $this->grantPermissions($user, ['deviations.view', 'deviations.read']);
        $this->getJson('/api/deviations')->assertOk();
        $this->postJson('/api/deviations/import')->assertForbidden();
        $this->grantPermissions($user, ['deviations.view', 'deviations.import']);
        $this->postJson('/api/deviations/import')->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->getJson('/api/deviations')->assertForbidden();
        $this->grantPermissions($user, ['deviations.read', 'deviations.import']);
        $this->getJson('/api/deviations')->assertForbidden();
        $this->postJson('/api/deviations/import')->assertForbidden();
        $this->patchJson("/api/admin/users/{$user->id}", ['status' => 'active', 'permissions' => ['deviations.view']])->assertForbidden();

        $admin = User::create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->getJson('/api/account')
            ->assertJsonPath('users_access.assign_roles', true)
            ->assertJsonPath('deviations_access.read', true)->assertJsonPath('deviations_access.import', true);
        $this->getJson('/api/admin/permissions')->assertJsonFragment(['key' => 'deviations.import', 'assignable' => true]);
        $this->patchJson("/api/admin/users/{$user->id}", [
            'status' => 'active', 'role_ids' => [\App\Models\Role::where('key', 'deviations-importer')->sole()->id],
        ])->assertOk();
        $this->actingAs($user->fresh())->getJson('/api/deviations')->assertOk();
        $user->update(['status' => 'blocked']);
        $this->actingAs($user)->getJson('/api/deviations')->assertForbidden();
    }

    public function test_grouped_xls_imports_shared_records_and_skips_overlapping_exports(): void
    {
        $admin = User::create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->postJson('/api/deviations/import', ['file' => $this->file([
            $this->group(1004, 2), $this->detail(), $this->detail('2025-12-06'),
        ])])->assertOk()->assertExactJson(['processed' => 2, 'inserted' => 2, 'duplicates' => 0]);
        $this->assertDatabaseHas('deviations', ['event_number' => '1004', 'flight_date' => '2025-12-05', 'parameter_value' => '7.75', 'report_event_count' => 2]);

        $this->postJson('/api/deviations/import', ['file' => $this->file([
            $this->group(1004, 99), $this->detail('2025-12-05', '7.750'), $this->detail('2025-12-07'),
        ], 'xlsx')])->assertOk()->assertExactJson(['processed' => 2, 'inserted' => 1, 'duplicates' => 1]);
        $this->assertDatabaseCount('deviations', 3);

        $reader = User::create(['permissions' => ['deviations.view', 'deviations.read'], 'status' => 'active']);
        $this->grantPermissions($reader, ['deviations.view', 'deviations.read']);
        $this->actingAs($reader)->getJson('/api/deviations')->assertOk()->assertJsonPath('total', 3)
            ->assertJsonMissingPath('data.0.fingerprint')->assertJsonMissingPath('data.0.uploaded_by');
    }

    public function test_invalid_import_never_partially_writes_or_evaluates_formulas(): void
    {
        $this->actingAs(User::create(['role' => 'admin', 'status' => 'active']));
        $invalid = $this->detail();
        $invalid[4] = 'not a date';
        $this->postJson('/api/deviations/import', ['file' => $this->file([
            $this->group(), $this->detail(), $invalid,
        ])])->assertUnprocessable()->assertJsonValidationErrors('file');
        $formula = $this->detail();
        $formula[9] = '=1+2';
        $this->postJson('/api/deviations/import', ['file' => $this->file([
            $this->group(), $formula,
        ])])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/deviations/import', ['file' => UploadedFile::fake()->createWithContent('bad.xls', '<html>not Excel</html>')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/deviations/import', ['file' => UploadedFile::fake()->create('large.xls', 10241)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/deviations/import', ['file' => $this->file([$this->group()])])->assertUnprocessable();
        $this->assertDatabaseCount('deviations', 0);
    }

    public function test_server_filters_sorting_grouping_and_pagination_are_validated(): void
    {
        $this->actingAs(User::create(['role' => 'admin', 'status' => 'active']));
        $this->postJson('/api/deviations/import', ['file' => $this->file([
            $this->group(1004), $this->detail('2025-12-05'), $this->detail('2025-12-07'),
            $this->group(1023), $this->detail('2025-12-06'),
        ])])->assertOk();
        $this->getJson('/api/deviations?'.http_build_query([
            'filters' => ['event_number' => '1004', 'captain_name' => 'Test'],
            'date_from' => '2025-12-06', 'date_to' => '2025-12-09',
        ]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.flight_date', '2025-12-07');
        $this->getJson('/api/deviations?date_to=2025-12-05')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/deviations?group_by=event_number&sort_by=flight_date&sort_order=asc&per_page=2')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.flight_date', '2025-12-05')->assertJsonPath('data.1.flight_date', '2025-12-07');
        $this->getJson('/api/deviations?group_by=event_number&sort_by=flight_date&sort_order=asc&per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.0.event_number', '1023');
        $this->getJson('/api/deviations?'.http_build_query(['filters' => ['captain_name' => '%']]))->assertOk()->assertJsonPath('total', 0);
        foreach (['per_page=501', 'per_page=0', 'page=0', 'sort_by=uploaded_by', 'sort_order=invalid', 'group_by=invalid',
            'filters[unknown]=x', 'date_from=2025-12-09&date_to=2025-12-01'] as $query) {
            $this->getJson('/api/deviations?'.$query)->assertUnprocessable();
        }
    }

    public function test_column_rules_are_combined_and_validated_on_the_server(): void
    {
        $this->actingAs($this->grantPermissions(User::create(['status' => 'active']), ['deviations.view', 'deviations.read', 'deviations.import']));
        $this->postJson('/api/deviations/import', ['file' => $this->file([
            $this->group(1004, 2), $this->detail('2025-12-05'), $this->detail('2025-12-07'),
            $this->group(1023, 1), $this->detail('2025-12-06'),
        ])])->assertOk();
        $rule = fn ($mode, $value) => ['matchMode' => $mode, 'value' => $value];
        $filter = fn ($constraints, $operator = 'and') => compact('operator', 'constraints');
        $request = fn ($rules) => $this->getJson('/api/deviations?'.http_build_query(['filter_rules' => $rules, 'per_page' => 1]));

        $request([
            'event_number' => $filter([$rule('equals', '1004'), $rule('equals', '1023')], 'or'),
            'flight_date' => $filter([$rule('gte', '2025-12-06'), $rule('lte', '2025-12-06')]),
        ])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.event_number', '1023');
        $request(['report_event_count' => $filter([$rule('gt', '1')])])
            ->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        foreach ([['contains', 'event', 3], ['startsWith', 'Test', 3], ['endsWith', '1004', 2],
            ['notContains', '1004', 1], ['equals', 'Test event 1004', 2], ['notEquals', 'Test event 1004', 1],
            ['contains', '%', 0], ['contains', '_', 0], ['contains', "' OR 1=1 --", 0]] as [$mode, $value, $total]) {
            $request(['event_text' => $filter([$rule($mode, $value)])])->assertOk()->assertJsonPath('total', $total);
        }
        foreach ([
            ['unknown' => $filter([$rule('equals', 'x')])],
            ['event_text' => $filter([$rule('contains', 'Test')], 'invalid')],
            ['event_text' => $filter([$rule('invalid', 'Test')])],
            ['event_text' => $filter([$rule('contains', '')])],
            ['event_text' => $filter([$rule('contains', ['x'])])],
            ['event_text' => $filter([$rule('contains', str_repeat('x', 256))])],
            ['event_text' => $filter(array_fill(0, 6, $rule('contains', 'x')))],
            ['flight_date' => $filter([$rule('gte', '2025-02-30')])],
            ['flight_date' => $filter([$rule('contains', '2025')])],
            ['report_event_count' => $filter([$rule('equals', '-1')])],
            ['report_event_count' => $filter([$rule('equals', 'abc')])],
            ['event_text' => ['constraints' => [$rule('contains', 'x')]]],
        ] as $invalid) {
            $request($invalid)->assertUnprocessable();
        }
    }

    public function test_actual_sample_can_be_imported_twice_without_duplicates(): void
    {
        $path = getenv('AIRFASE_SAMPLE');
        if (! $path || ! is_file($path)) {
            $this->markTestSkipped('Set AIRFASE_SAMPLE to check a local real export (never committed).');
        }
        $admin = User::create(['role' => 'admin', 'status' => 'active']);
        $file = new UploadedFile($path, basename($path), null, null, true);
        $importer = app(DeviationImporter::class);
        $first = $importer->import($file, $admin->id);
        $second = $importer->import($file, $admin->id);
        $this->assertGreaterThan(500, $first['inserted']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame($second['processed'], $second['duplicates']);
        $this->actingAs($admin)->getJson('/api/deviations?per_page=500')->assertOk()->assertJsonCount(500, 'data');
        $this->getJson('/api/deviations?per_page=500&page=2')->assertOk()->assertJsonPath('total', $first['inserted']);
    }

    private function group(int $number = 1004, int $count = 1): array
    {
        return [$number, 'Test event '.$number, $count];
    }

    private function detail(string $date = '2025-12-05', string $value = '7,75'): array
    {
        return [null, null, null, 'Medium', Date::PHPToExcel(new \DateTimeImmutable($date)),
            'RRJ-95B', 89124, 6138, 'SPEED LIMIT', $value, 'Test Captain', 123456, null, null, null, null];
    }

    private function file(array $rows, string $extension = 'xls'): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([array_values(Deviation::COLUMNS), ...$rows]);
        foreach ($rows as $index => $row) {
            if (isset($row[9]) && str_starts_with((string) $row[9], '=')) {
                $sheet->setCellValueExplicit('J'.($index + 2), $row[9], DataType::TYPE_FORMULA);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'deviation-test-');
        $this->temporaryFiles[] = $path;
        $writer = $extension === 'xls' ? new Xls($book) : new Xlsx($book);
        $writer->setPreCalculateFormulas(false)->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'report.'.$extension, null, null, true);
    }
}
