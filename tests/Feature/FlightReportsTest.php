<?php
namespace Tests\Feature;
use App\Models\{FlightReport, FlightSegment, InternalApiToken, ParserTask, PortalProfile, RosterItem, SyncRun, User};
use App\Services\ParserTaskScheduler;
use Illuminate\Support\Facades\{Artisan, DB, Storage};
use Tests\TestCase;
class FlightReportsTest extends TestCase {
    private $user; private $segment; private $run;
    protected function setUp(): void {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']); DB::purge('sqlite');
        Artisan::call('migrate',['--force'=>true]); Storage::fake('flight_reports');
        $this->user=User::create(['status'=>'active','role'=>'admin']);
        PortalProfile::create(['user_id'=>$this->user->id,'source'=>'rossiya_edu','full_name'=>'Test Pilot','personnel_number'=>'456','synced_at'=>now()]);
        $item=RosterItem::create(['user_id'=>$this->user->id,'source'=>'rossiya_edu','source_external_id'=>'1708557',
            'source_request_raw'=>'1708557,19.10.2026,1','is_actual'=>true,'is_removed_from_source'=>false,
            'starts_at'=>now()->subDays(3),'ends_at'=>now()->subDays(2)]);
        $this->segment=FlightSegment::create(['user_id'=>$this->user->id,'roster_item_id'=>$item->id,'source'=>'rossiya_edu',
            'source_para_id'=>'1708557','flight_number'=>'ФВ6167','starts_at'=>now()->subDays(3),'ends_at'=>now()->subDays(2)]);
        $this->run=SyncRun::create(['user_id'=>$this->user->id,'source'=>'rossiya_edu','trigger'=>'scheduler','status'=>'running',
            'task_type'=>'flight_report','roster_item_id'=>$item->id,'started_at'=>now(),'lock_expires_at'=>now()->addMinutes(15)]);
        InternalApiToken::create(['name'=>'test','token_hash'=>InternalApiToken::hashToken('report-test'),'is_active'=>true]);
    }
    private function workbook(string $value = 'test'): string {
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook>'.$value.'</workbook>');
        $zip->addFromString('[Content_Types].xml', '<Types/>'); $zip->close();
        $bytes = file_get_contents($path); unlink($path); return $bytes;
    }
    private function payload(): array {
        return ['flight_number'=>'ФВ6167','starts_at'=>$this->segment->starts_at->toIso8601String(),
            'source_url'=>'https://portal.example/workplan/view_flight_report-1?flight_number=ФВ6167&id_para=1708557&legnum=1',
            'content_base64'=>base64_encode($this->workbook()),
            'report'=>['schema_version'=>1,'flight_number'=>'ФВ6167','flight_date'=>$this->segment->starts_at->toDateString(),
                'airborne_minutes'=>71,'flight_minutes'=>97,'night_minutes'=>0,'takeoff_pf'=>'456','landing_pf'=>'456',
                'crew'=>[['role'=>'PIC','personnel_number'=>'123'],['role'=>'PIC','personnel_number'=>'456']],
                'sheets'=>[['cells'=>['A1'=>['value'=>'preserved']]]]]];
    }
    private function upload(array $data = []) {
        return $this->withToken('report-test')->postJson('/api/internal/sync-runs/'.$this->run->id.'/flight-report', $data ?: $this->payload());
    }
    public function test_idempotent_import_statistics_scope_and_retention(): void {
        $this->upload()->assertOk(); $this->upload()->assertOk();
        $this->assertDatabaseCount('flight_reports',1);
        $report=FlightReport::sole(); $path=$report->storage_path;
        $this->assertSame('preserved', $report->data['sheets'][0]['cells']['A1']['value']);
        $this->assertSame($this->segment->starts_at->toDateString(), $report->flight_date->toDateString());
        $this->actingAs($this->user)->getJson('/api/statistics')->assertOk()->assertJsonPath('totals.airborne_minutes',71)
            ->assertJsonPath('totals.flight_minutes',97)->assertJsonPath('totals.takeoffs',1)->assertJsonPath('totals.landings',1);
        $this->getJson('/api/statistics?month=2000-01')->assertOk()->assertJsonPath('totals.flights',0);
        $other=User::create(['status'=>'active','role'=>'admin']);
        $this->actingAs($other)->getJson('/api/statistics')->assertJsonPath('totals.flights',0);
        $this->getJson('/api/statistics/reports/'.$report->id)->assertNotFound();
        $this->getJson('/api/statistics/reports/'.$report->id.'/file')->assertNotFound();
        $this->actingAs($this->user)->get('/api/statistics/reports/'.$report->id.'/file')->assertOk();
        $this->travel(61)->days(); Artisan::call('flight-reports:prune');
        Storage::disk('flight_reports')->assertMissing($path);
        $this->assertNull($report->fresh()->storage_path);
        $this->getJson('/api/statistics/reports/'.$report->id.'/file')->assertNotFound();
        $this->assertSame(71,$report->fresh()->data['airborne_minutes']);
    }
    public function test_revised_report_replaces_contribution_without_touching_roster(): void {
        $before=$this->segment->fresh()->toArray(); $this->upload()->assertOk();
        $data=$this->payload(); $data['report']['airborne_minutes']=80; $data['content_base64']=base64_encode($this->workbook('new'));
        $this->upload($data)->assertOk();
        $this->assertDatabaseCount('flight_reports',1);
        $this->assertSame($before,$this->segment->fresh()->toArray());
        $this->actingAs($this->user)->getJson('/api/statistics')->assertJsonPath('totals.airborne_minutes',80);
        $this->assertCount(1,Storage::disk('flight_reports')->allFiles());
    }
    public function test_validation_and_lease(): void {
        $data=$this->payload(); $data['content_base64']=base64_encode('<html>login</html>'); $this->upload($data)->assertUnprocessable();
        $data=$this->payload(); $data['report']['flight_number']='OTHER'; $this->upload($data)->assertUnprocessable();
        $data=$this->payload(); $data['flight_number']='OTHER'; $data['report']['flight_number']='OTHER'; $this->upload($data)->assertNotFound();
        $this->run->update(['status'=>'finished']); $this->upload()->assertConflict();
    }
    public function test_only_capable_workers_claim_report_jobs(): void {
        \App\Models\PortalCredential::create(['user_id'=>$this->user->id,'portal'=>'rossiya_edu','login'=>'test',
            'password_encrypted'=>\Illuminate\Support\Facades\Crypt::encryptString('password'),'status'=>'active']);
        Artisan::call('flight-reports:schedule');
        ParserTask::where('task_type','flight_report')->update(['priority'=>200]);
        $options=['source'=>'rossiya_edu','portal'=>'rossiya_edu','locked_by'=>'test','capabilities'=>['typed_tasks_v1']];
        $old=$this->withToken('report-test')->postJson('/api/internal/parser-jobs/claim',$options)->assertOk()->json('job');
        $this->assertNotSame('flight_report',$old['task_type'] ?? null);
        $options['capabilities'][]='flight_reports_v1';
        $new=$this->postJson('/api/internal/parser-jobs/claim',$options)->assertOk()->json('job');
        $this->assertSame('flight_report',$new['task_type']);
        $this->assertSame('1708557,19.10.2026,1',$new['task_payload']['source_request_raw']);
    }
    public function test_historical_queue_is_idempotent_and_daily_retry_is_bounded(): void {
        Artisan::call('flight-reports:schedule'); Artisan::call('flight-reports:schedule');
        $task=ParserTask::where('task_type','flight_report')->sole();
        $this->run->update(['parser_task_id'=>$task->id,'status'=>'finished','finished_at'=>now()]);
        app(ParserTaskScheduler::class)->completeTask($this->run);
        $this->assertSame('scheduled',$task->fresh()->status);
        $this->assertTrue($task->fresh()->next_run_at->toDateTimeString() === now()->addDay()->toDateTimeString());
        $this->travel(8)->days(); $this->run->update(['finished_at'=>now()]);
        app(ParserTaskScheduler::class)->completeTask($this->run);
        $this->assertSame('completed',$task->fresh()->status);
    }
}
