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
        Artisan::call('migrate',['--force'=>true]); Storage::fake('flight_reports'); Storage::fake('flight_files');
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
        Storage::disk('flight_files')->assertMissing($path);
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
        $this->assertCount(1,Storage::disk('flight_files')->allFiles());
    }
    public function test_existing_files_move_together_and_prune_independently(): void {
        Storage::fake('flight_documents');
        $this->upload()->assertOk();
        $report = FlightReport::sole();
        $bytes = Storage::disk('flight_files')->get($report->storage_path);
        Storage::disk('flight_files')->delete($report->storage_path);
        Storage::disk('flight_reports')->put('legacy.xlsx', $bytes);
        $report->update(['storage_disk'=>'flight_reports', 'storage_path'=>'legacy.xlsx']);
        $expiry = $report->expires_at->toIso8601String();
        foreach (['ofp', 'epz'] as $type) {
            $pdf = '%PDF-'.$type;
            Storage::disk('flight_documents')->put($type.'.pdf', $pdf);
            \App\Models\FlightDocument::create(['user_id'=>$this->user->id, 'flight_segment_id'=>$this->segment->id,
                'document_type'=>$type, 'source_url'=>'https://portal.example/'.$type.'.pdf', 'storage_path'=>$type.'.pdf', 'content_hash'=>hash('sha256', $pdf),
                'expires_at'=>now()->addDays(60)]);
        }
        $this->actingAs($this->user)->get('/api/statistics/reports/'.$report->id.'/file')->assertOk();
        $this->assertSame(0, Artisan::call('flight-files:organize'));
        $this->assertSame(0, Artisan::call('flight-files:organize'));
        $folder = $this->user->id.'/'.$this->segment->id;
        foreach (['ofp.pdf', 'epz.pdf', 'report.xlsx'] as $file) Storage::disk('flight_files')->assertExists($folder.'/'.$file);
        $this->assertSame($bytes, Storage::disk('flight_files')->get($folder.'/report.xlsx'));
        $this->assertSame($expiry, $report->fresh()->expires_at->toIso8601String());
        $this->assertEmpty(Storage::disk('flight_documents')->allFiles());
        $this->assertEmpty(Storage::disk('flight_reports')->allFiles());
        $this->get('/api/statistics/reports/'.$report->id.'/file')->assertOk();
        $report->update(['expires_at'=>now()->subDay()]);
        Storage::disk('flight_files')->put('monitor-metrics/keep.json', '{}');
        Artisan::call('flight-reports:prune');
        Artisan::call('flight-documents:prune');
        Storage::disk('flight_files')->assertMissing($folder.'/report.xlsx');
        Storage::disk('flight_files')->assertExists($folder.'/ofp.pdf');
        Storage::disk('flight_files')->assertExists($folder.'/epz.pdf');
        Storage::disk('flight_files')->assertExists('monitor-metrics/keep.json');
        $this->assertNotEmpty($report->fresh()->data);
    }
    public function test_move_missing_source_keeps_database_reference(): void {
        $this->upload()->assertOk();
        $report = FlightReport::sole();
        $report->update(['storage_disk'=>'flight_reports', 'storage_path'=>'missing.xlsx']);
        $this->assertSame(1, Artisan::call('flight-files:organize'));
        $this->assertSame('missing.xlsx', $report->fresh()->storage_path);
        $this->assertSame('flight_reports', $report->fresh()->storage_disk);
    }
    public function test_card_times_signed_deviations_and_assignment_link_survive_missing_segment(): void {
        $data = $this->payload();
        $data['report']['times'] = ['Время запуска двигателей'=>'05:55:00', 'Время взлета (отрыва)'=>'06:11:00', 'Время посадки'=>'07:22:00', 'Время выключения двигателей'=>'07:32:00', 'Задержка'=>'-00:05'];
        $data['report']['duty_duration'] = null;
        $this->upload($data)->assertOk();
        $second = $data;
        $second['flight_number'] = $second['report']['flight_number'] = 'ФВ6856';
        $second['source_url'] = str_replace('ФВ6167', 'ФВ6856', $data['source_url']);
        $second['report']['times']['Задержка'] = '+00:02';
        $second['report']['times']['Время запуска двигателей'] = '13:20:00';
        $second['report']['duty_duration'] = '10:45:00';
        $this->upload($second)->assertOk();
        $flights = collect($this->actingAs($this->user)->getJson('/api/statistics')->assertOk()->json('flights'))->keyBy('flight_number');
        $this->assertSame(-5, $flights['ФВ6167']['delay_minutes']);
        $this->assertSame(2, $flights['ФВ6856']['delay_minutes']);
        $this->assertSame('05:55:00', $flights['ФВ6167']['engine_start']);
        $this->assertSame('07:32:00', $flights['ФВ6167']['engine_shutdown']);
        $this->assertSame('06:11:00', $flights['ФВ6167']['takeoff_time']);
        $this->assertSame('07:22:00', $flights['ФВ6167']['landing_time']);
        $this->assertNull($flights['ФВ6167']['duty_minutes']);
        $this->assertSame(645, $flights['ФВ6856']['duty_minutes']);
        $this->assertSame($this->run->roster_item_id, $flights['ФВ6167']['assignment_id']);
        $this->assertSame($flights['ФВ6167']['assignment_id'], $flights['ФВ6856']['assignment_id']);
        $data['report']['times']['Задержка'] = '00:00';
        $this->upload($data)->assertOk();
        $data['report']['times']['Задержка'] = 'not a duration';
        $data['report']['duty_duration'] = '';
        $this->upload($data)->assertOk();
        $flights = collect($this->getJson('/api/statistics')->assertOk()->json('flights'))->keyBy('flight_number');
        $this->assertNull($flights['ФВ6167']['delay_minutes']);
        $this->assertNull($flights['ФВ6167']['duty_minutes']);
    }
    public function test_duty_in_merged_g_h_cell_is_recovered_for_existing_and_new_reports(): void {
        $data = $this->payload();
        $data['report']['duty_duration'] = null;
        $data['report']['sheets'][0] = ['merged_ranges'=>['A148:F148', 'G148:H148'], 'cells'=>[
            'A148'=>['value'=>'Продолжительность полетной смены', 'type'=>'s', 'format'=>'General'],
            'G148'=>['value'=>'07:46:00', 'type'=>'d', 'format'=>'h:mm'],
        ]];
        $this->upload($data)->assertOk();
        $report = FlightReport::sole();
        $this->assertSame('7:46', $report->summary['duty_duration']);
        $hash = $report->content_hash;
        $path = $report->storage_path;
        $expiry = $report->expires_at->toIso8601String();
        $legacyData = $report->data;
        $legacySummary = $report->summary;
        $legacyData['duty_duration'] = $legacySummary['duty_duration'] = null;
        $report->update(['data'=>$legacyData, 'summary'=>$legacySummary]);
        $this->assertSame(0, Artisan::call('flight-reports:repair-duty'));
        $this->assertSame(0, Artisan::call('flight-reports:repair-duty'));
        $report->refresh();
        $this->assertSame('7:46', $report->data['duty_duration']);
        $this->assertSame('7:46', $report->summary['duty_duration']);
        $this->assertSame($hash, $report->content_hash);
        $this->assertSame($path, $report->storage_path);
        $this->assertSame($expiry, $report->expires_at->toIso8601String());
        $this->actingAs($this->user)->getJson('/api/statistics')->assertOk()->assertJsonPath('flights.0.duty_minutes', 466);
        $legacyData['sheets'][0]['cells']['G148']['value'] = null;
        $durations = app(\App\Services\FlightReportDutyDuration::class);
        $this->assertNull($durations->extract($legacyData));
        $legacyData['sheets'][0]['cells']['G148']['value'] = '=1/3';
        $this->assertNull($durations->extract($legacyData));
        $this->assertSame('27:46', $durations->normalize(99960));
    }
    public function test_validation_and_lease(): void {
        $data=$this->payload(); $data['content_base64']=base64_encode('<html>login</html>'); $this->upload($data)->assertUnprocessable();
        $data=$this->payload(); $data['report']['flight_number']='OTHER'; $this->upload($data)->assertUnprocessable();
        $data=$this->payload(); $data['flight_number']='OTHER'; $data['report']['flight_number']='OTHER'; $this->upload($data)->assertUnprocessable();
        $this->run->update(['status'=>'finished']); $this->upload()->assertConflict();
    }
    public function test_old_report_without_segment_is_imported_without_changing_roster(): void {
        $item = RosterItem::findOrFail($this->run->roster_item_id);
        $item->update(['starts_at'=>now()->subMonths(3), 'ends_at'=>now()->subMonths(3)->addHours(8)]);
        $data = $this->payload();
        $data['starts_at'] = $item->starts_at->toIso8601String();
        $data['report']['flight_date'] = $item->starts_at->toDateString();
        $this->segment->delete();
        $before = $item->fresh()->toArray();
        Artisan::call('flight-reports:schedule');
        $this->assertSame(1, ParserTask::where('task_type','flight_report')->count());
        $invalid = $data;
        $invalid['source_url'] = str_replace('1708557', '999', $data['source_url']);
        $this->upload($invalid)->assertUnprocessable();
        $this->upload($data)->assertOk();
        $this->upload($data)->assertOk();
        $this->assertDatabaseCount('flight_reports', 1);
        $this->assertNull(FlightReport::sole()->flight_segment_id);
        $this->assertDatabaseCount('flight_segments', 0);
        $this->assertSame($before, $item->fresh()->toArray());
        $this->actingAs($this->user)->getJson('/api/statistics?month='.$item->starts_at->format('Y-m'))
            ->assertOk()->assertJsonPath('totals.flights', 1);
    }
    public function test_history_with_missing_end_date_is_queued_and_imported(): void {
        $item = RosterItem::findOrFail($this->run->roster_item_id);
        $item->update(['starts_at'=>now()->subMonths(2), 'ends_at'=>null]);
        $this->segment->delete();
        $data = $this->payload();
        $data['starts_at'] = $item->starts_at->copy()->addDay()->toIso8601String();
        $data['report']['flight_date'] = $item->starts_at->copy()->addDay()->toDateString();
        Artisan::call('flight-reports:schedule');
        $this->assertSame(1, ParserTask::where('task_type','flight_report')->count());
        $this->upload($data)->assertOk();
        $this->assertNull($item->fresh()->ends_at);
        $this->assertDatabaseCount('flight_segments', 0);
        $future = $data;
        $future['starts_at'] = now()->addDay()->toIso8601String();
        $future['report']['flight_date'] = now()->addDay()->toDateString();
        $this->upload($future)->assertUnprocessable();
        $item->update(['starts_at'=>now()->addDay()]);
        ParserTask::query()->delete();
        Artisan::call('flight-reports:schedule');
        $this->assertSame(0, ParserTask::where('task_type','flight_report')->count());
        $this->upload($data)->assertConflict();
    }
    public function test_explicit_retry_reopens_old_tasks_but_leaves_running_tasks_alone(): void {
        Artisan::call('flight-reports:schedule');
        $task = ParserTask::where('task_type','flight_report')->sole();
        $task->update(['status'=>'completed', 'next_run_at'=>null]);
        $this->travel(10)->days();
        Artisan::call('flight-reports:schedule');
        $this->assertSame('completed', $task->fresh()->status);
        Artisan::call('flight-reports:schedule', ['--retry'=>true]);
        $this->assertSame('scheduled', $task->fresh()->status);
        $this->run->update(['parser_task_id'=>$task->id, 'status'=>'failed', 'finished_at'=>now(), 'error_text'=>'retryable']);
        app(ParserTaskScheduler::class)->completeTask($this->run);
        $this->assertSame('scheduled', $task->fresh()->status);
        $task->update(['status'=>'running']);
        $before = $task->fresh()->toArray();
        Artisan::call('flight-reports:schedule', ['--retry'=>true]);
        $this->assertSame($before, $task->fresh()->toArray());
        $this->travel(8)->days();
        $this->run->update(['finished_at'=>now()]);
        app(ParserTaskScheduler::class)->completeTask($this->run);
        $this->assertSame('completed', $task->fresh()->status);
    }
    public function test_statistics_and_report_download_require_extended_subscription(): void {
        $this->upload()->assertOk();
        $report = FlightReport::sole();
        $user = User::create(['status'=>'active','role'=>'user']);
        $report->update(['user_id'=>$user->id]);
        $urls = ['/api/statistics', '/api/statistics/reports/'.$report->id, '/api/statistics/reports/'.$report->id.'/file'];
        $this->actingAs($user);
        foreach ($urls as $url) $this->getJson($url)->assertForbidden();
        $payment = \App\Models\SubscriptionPayment::create([
            'user_id'=>$user->id, 'request_id'=>(string) \Illuminate\Support\Str::uuid(), 'tier'=>'basic',
            'amount_kopecks'=>100, 'duration_days'=>30, 'paid_at'=>now(), 'starts_at'=>now()->subDay(),
            'ends_at'=>now()->addDays(30), 'recorded_by'=>$this->user->id, 'grants_access'=>true,
        ]);
        foreach ($urls as $url) $this->getJson($url)->assertForbidden();
        $payment->update(['tier'=>'extended']);
        foreach ($urls as $url) $this->getJson($url)->assertOk();
        $this->assertTrue($user->fresh()->subscriptionSummary()['statistics_access']);
        $other = User::create(['status'=>'active','role'=>'user']);
        $otherPayment = $payment->replicate();
        $otherPayment->user_id = $other->id;
        $otherPayment->request_id = (string) \Illuminate\Support\Str::uuid();
        $otherPayment->save();
        $report->update(['user_id'=>$other->id]);
        $this->assertTrue($other->hasReportsAccess());
        $this->assertFalse($other->subscriptionSummary()['statistics_access']);
        $this->actingAs($other);
        foreach ($urls as $url) $this->getJson($url)->assertForbidden();
        $report->update(['user_id'=>$user->id]);
        $this->actingAs($user);
        $payment->update(['ends_at'=>now()->subDay()]);
        foreach ($urls as $url) $this->getJson($url)->assertForbidden();
        $this->assertDatabaseCount('flight_reports',1);
    }
    public function test_history_refresh_queues_each_month_and_finishes_once(): void {
        \App\Models\PortalCredential::create(['user_id'=>$this->user->id,'portal'=>'rossiya_edu','login'=>'test',
            'password_encrypted'=>\Illuminate\Support\Facades\Crypt::encryptString('password'),'status'=>'active']);
        $this->assertSame(1, Artisan::call('roster:refresh-history', ['--from'=>'invalid', '--to'=>'2026-10']));
        $from = now()->subMonthsNoOverflow(2)->format('Y-m');
        $options = ['--from'=>$from, '--to'=>now()->format('Y-m')];
        $this->assertSame(0, Artisan::call('roster:refresh-history', $options));
        Artisan::call('roster:refresh-history', $options);
        $this->assertSame(4, ParserTask::where('task_type','roster_refresh')->count());
        $task = ParserTask::where('task_key', 'like', 'roster_history:%')->orderBy('id')->firstOrFail();
        $task->update(['priority'=>200]);
        $job = $this->withToken('report-test')->postJson('/api/internal/parser-jobs/claim', [
            'source'=>'rossiya_edu', 'portal'=>'rossiya_edu', 'locked_by'=>'history-test',
            'capabilities'=>['typed_tasks_v1'],
        ])->assertOk()->json('job');
        $this->assertSame([$from], $job['task_payload']['months']);
        $this->assertSame('running', $task->fresh()->status);
        $before = $task->fresh()->toArray();
        Artisan::call('roster:refresh-history', $options);
        $this->assertSame($before, $task->fresh()->toArray());
        $run = SyncRun::where('parser_task_id', $task->id)->latest('id')->firstOrFail();
        $run->update(['status'=>'finished', 'finished_at'=>now()]);
        app(ParserTaskScheduler::class)->completeTask($run);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNull($task->fresh()->next_run_at);
        $regular = ParserTask::where('task_key', 'like', 'roster_refresh:%')->sole();
        $this->assertSame('scheduled', $regular->status);
        $this->assertEmpty($regular->payload);
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
