<?php
namespace Tests\Feature;

use App\Models\{User, PilotCheck, SyncRun, PortalCredential, ParserTask, InternalApiToken, TelegramAccount, PushSubscription};
use App\Services\{ParserJobService, ParserTaskScheduler, WebPushSender};
use Illuminate\Support\Facades\{Artisan, Cache, Crypt, DB, Http};
use Tests\TestCase;

class PilotChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->travelTo(now('UTC')->setDate(2026, 10, 12)->setTime(22, 0));
    }
    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }
    private function admin(): User { return User::create(['status' => 'active', 'role' => 'admin', 'timezone' => 'Asia/Krasnoyarsk']); }
    public function test_access_owner_isolation_and_portal_dates_are_read_only(): void
    {
        $this->getJson('/api/pilot-checks')->assertUnauthorized();
        $basic = User::create(['status' => 'active', 'role' => 'user']);
        $this->actingAs($basic)->getJson('/api/pilot-checks')->assertForbidden();
        $owner = $this->admin();
        $this->actingAs($owner)->postJson('/api/pilot-checks', ['title' => 'Проверка', 'due_on' => '2026-11-01', 'user_id' => $basic->id, 'origin' => 'portal'])->assertCreated();
        $check = PilotCheck::first();
        $this->assertSame($owner->id, $check->user_id);
        $this->assertSame('manual', $check->origin);
        $other = $this->admin();
        $this->actingAs($other)->patchJson('/api/pilot-checks/'.$check->id, ['title' => 'Изменить', 'due_on' => '2026-12-01'])->assertNotFound();
        $this->deleteJson('/api/pilot-checks/'.$check->id)->assertNotFound();
        $portal = PilotCheck::create(['user_id' => $owner->id, 'origin' => 'portal', 'title' => 'Квалификационная проверка', 'due_on' => '2026-11-01']);
        $this->actingAs($owner)->patchJson('/api/pilot-checks/'.$portal->id, ['title' => 'Изменить', 'due_on' => '2026-12-01'])->assertNotFound();
        $this->deleteJson('/api/pilot-checks/'.$portal->id)->assertNotFound();
        $this->patchJson('/api/pilot-checks/'.$check->id, ['title' => 'Новая', 'due_on' => '2026-11-03'])->assertOk();
        $this->deleteJson('/api/pilot-checks/'.$check->id)->assertOk();
        $this->assertDatabaseHas('pilot_checks', ['id' => $portal->id, 'due_on' => '2026-11-01']);
    }
    public function test_only_active_extended_subscription_grants_access_and_reminders_can_be_disabled(): void
    {
        $admin = $this->admin();
        $user = User::create(['status' => 'active', 'role' => 'user']);
        $payment = \App\Models\SubscriptionPayment::create([
            'user_id' => $user->id, 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'tier' => 'basic',
            'amount_kopecks' => 100, 'duration_days' => 30, 'paid_at' => now(), 'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30), 'recorded_by' => $admin->id, 'grants_access' => true,
        ]);
        $this->actingAs($user)->getJson('/api/pilot-checks')->assertForbidden();
        $payment->update(['tier' => 'extended']);
        $this->getJson('/api/pilot-checks')->assertOk();
        $this->getJson('/api/account')->assertJsonFragment(['pilot-checks']);
        $this->patchJson('/api/pilot-checks/settings', ['notifications_enabled' => false])->assertOk();
        $this->assertFalse($user->fresh()->check_notifications_enabled);
        PilotCheck::create(['user_id' => $user->id, 'origin' => 'manual', 'title' => 'Проверка', 'due_on' => now()->addDays(14)->toDateString()]);
        config(['services.telegram_bot.token' => null]);
        Artisan::call('checks:notify');
        $this->assertDatabaseCount('pilot_check_deliveries', 0);
        $payment->update(['canceled_at' => now()]);
        $this->getJson('/api/pilot-checks')->assertForbidden();
        $this->postJson('/api/pilot-checks', ['title' => 'Проверка', 'due_on' => '2026-11-01'])->assertForbidden();
    }

    public function test_only_requested_portal_checks_are_visible_and_old_extras_never_notify(): void
    {
        $user = $this->admin();
        $user->forceFill(['telegram_notifications_enabled' => true])->save();
        TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 123]);
        config(['services.telegram_bot.token' => 'test']);
        Http::fake();
        $titles = ['КВАЛИФ ПРОВЕРКА … [Квалификационная проверка]',
            'ПППИ … [Подтверждение полномочий ПИ]', 'ПППЭ … [Подтверждение полномочий пилота-экзаменатора]'];
        foreach ([[$titles[0], 'С9Н'], [$titles[0], 'СУ95'], [$titles[1], 'С9Н'], [$titles[1], 'СУ95'], [$titles[2], '-']] as [$title, $aircraft]) {
            PilotCheck::create(['user_id' => $user->id, 'origin' => 'portal', 'title' => $title, 'aircraft_type' => $aircraft, 'due_on' => '2027-01-01']);
        }
        $unwanted = 'ТП (МВЛ) … [Техника пилотирования (МВЛ)]';
        PilotCheck::create(['user_id' => $user->id, 'origin' => 'portal', 'title' => $unwanted, 'due_on' => '2026-10-27']);
        $manual = PilotCheck::create(['user_id' => $user->id, 'origin' => 'manual', 'title' => $unwanted, 'due_on' => '2027-01-01']);
        $response = $this->actingAs($user)->getJson('/api/pilot-checks')->assertOk()->assertJsonCount(6, 'checks');
        $this->assertContains($manual->id, array_column($response->json('checks'), 'id'));
        Artisan::call('checks:notify');
        Http::assertNothingSent();
        $this->assertDatabaseCount('pilot_check_deliveries', 0);
    }

    public function test_snapshot_is_idempotent_preserves_manual_and_rejects_wrong_or_expired_runs(): void
    {
        $user = $this->admin();
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('checks-test'), 'is_active' => true]);
        $manual = PilotCheck::create(['user_id' => $user->id, 'origin' => 'manual', 'title' => 'Своя', 'due_on' => '2026-12-01']);
        $run = SyncRun::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler', 'task_type' => 'checks_refresh', 'status' => 'running', 'started_at' => now(), 'lock_expires_at' => now()->addMinutes(10)]);
        $url = '/api/internal/sync-runs/'.$run->id.'/checks';
        $rows = [['title' => 'Квалификационная проверка', 'aircraft_type' => 'СУ95', 'valid_from' => '2025-10-21', 'due_on' => '2026-10-21']];
        $this->withToken('checks-test')->postJson($url, ['checks' => array_merge($rows, [['title' => 'ТП (МВЛ) … [Техника пилотирования (МВЛ)]', 'due_on' => '2026-10-21']])])->assertOk();
        $this->postJson($url, ['checks' => $rows])->assertOk();
        $this->assertDatabaseCount('pilot_checks', 2);
        $this->postJson($url, ['checks' => [['title' => 'bad', 'due_on' => 'nonsense']]])->assertUnprocessable();
        $this->assertDatabaseHas('pilot_checks', ['origin' => 'portal', 'is_current' => true]);
        $run->update(['lock_expires_at' => now()->subMinute()]);
        $this->postJson($url, ['checks' => []])->assertConflict();
        $this->assertDatabaseHas('pilot_checks', ['id' => $manual->id]);
        $run2 = $run->replicate(); $run2->lock_expires_at = now()->addMinutes(10); $run2->save();
        $this->postJson('/api/internal/sync-runs/'.$run2->id.'/checks', ['checks' => []])->assertOk();
        $this->assertDatabaseHas('pilot_checks', ['origin' => 'portal', 'is_current' => false]);
        $this->assertTrue($manual->fresh()->is_current);
    }
    public function test_checks_tasks_require_capability_and_reschedule_after_24_hours(): void
    {
        $user = $this->admin();
        PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu', 'login' => 'test', 'password_encrypted' => Crypt::encryptString('password'), 'status' => 'active']);
        app(ParserTaskScheduler::class)->ensureCheckTasks('rossiya_edu', 'rossiya_edu');
        ParserTask::where('task_type', 'checks_refresh')->update(['priority' => 200]);
        $legacy = app(ParserJobService::class)->claim(['user_id' => $user->id, 'capabilities' => ['typed_tasks_v1']]);
        $this->assertSame('roster_refresh', $legacy['task_type']);
        $job = app(ParserJobService::class)->claim(['user_id' => $user->id, 'capabilities' => ['typed_tasks_v1', 'checks_v1']]);
        $this->assertSame('checks_refresh', $job['task_type']);
        $run = SyncRun::find($job['sync_run_id']);
        $run->update(['status' => 'finished', 'finished_at' => now()]);
        app(ParserTaskScheduler::class)->completeTask($run);
        $this->assertTrue(ParserTask::find($job['task_id'])->next_run_at->equalTo(now()->addHours(24)));
    }

    public function test_repeated_check_task_discovery_batches_existing_tasks_without_loading_users_or_payments(): void
    {
        $password = Crypt::encryptString('test');
        for ($i = 0; $i < 105; $i++) {
            $user = $this->admin();
            PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu',
                'login' => 'test-'.$i, 'password_encrypted' => $password, 'status' => 'active']);
            ParserTask::create(['task_key' => "checks_refresh:rossiya_edu:user:{$user->id}",
                'user_id' => $user->id, 'source' => 'rossiya_edu', 'portal' => 'rossiya_edu',
                'task_type' => 'checks_refresh', 'status' => $i % 2 ? 'running' : 'scheduled',
                'next_run_at' => now()->addHours(24)]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(ParserTaskScheduler::class)->ensureCheckTasks('rossiya_edu', 'rossiya_edu');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        // Two chunks, each with a credential query and a task-state query.
        $this->assertCount(4, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower($query['query']));
            $this->assertStringNotContainsString('subscription_payments', $query['query']);
            $this->assertStringNotContainsString('"users"', $query['query']);
        }
        $this->assertDatabaseCount('parser_tasks', 105);
        $this->assertSame(53, ParserTask::where('status', 'scheduled')->count());
        $this->assertSame(52, ParserTask::where('status', 'running')->count());
        $this->assertSame(105, ParserTask::where('next_run_at', now()->addHours(24))->count());
    }

    public function test_check_task_discovery_creates_missing_and_restores_paused_tasks_only_with_access(): void
    {
        $tasks = [];
        foreach (['missing', 'paused', 'completed', 'no_access'] as $state) {
            $user = $state === 'no_access' ? User::create(['status' => 'active', 'role' => 'user']) : $this->admin();
            PortalCredential::create(['user_id' => $user->id, 'portal' => 'rossiya_edu',
                'login' => $state, 'password_encrypted' => Crypt::encryptString('test'), 'status' => 'active']);
            $tasks[$state] = "checks_refresh:rossiya_edu:user:{$user->id}";
            if ($state !== 'missing') {
                ParserTask::create(['task_key' => $tasks[$state], 'user_id' => $user->id,
                    'source' => 'rossiya_edu', 'portal' => 'rossiya_edu', 'task_type' => 'checks_refresh',
                    'status' => $state === 'no_access' ? 'paused' : $state]);
            }
        }

        app(ParserTaskScheduler::class)->ensureCheckTasks('rossiya_edu', 'rossiya_edu');

        foreach (['missing', 'paused', 'completed'] as $state) {
            $task = ParserTask::where('task_key', $tasks[$state])->sole();
            $this->assertSame('scheduled', $task->status);
            $this->assertTrue($task->next_run_at->equalTo(now()));
        }
        $this->assertDatabaseHas('parser_tasks', ['task_key' => $tasks['no_access'], 'status' => 'paused']);
        $this->assertDatabaseCount('parser_tasks', 4);
    }

    public function test_reminders_use_local_dates_retry_failures_and_deduplicate_each_recipient(): void
    {
        config(['services.telegram_bot.token' => 'test']);
        $user = $this->admin();
        $user->forceFill(['telegram_notifications_enabled' => true])->save();
        TelegramAccount::create(['user_id' => $user->id, 'telegram_id' => 123]);
        $device = PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://example.test', 'endpoint_hash' => hash('sha256', 'test'), 'keys' => []]);
        $push = $this->mock(WebPushSender::class);
        $push->shouldReceive('configured')->andReturn(true);
        $push->shouldReceive('send')->once()->andReturn('sent');
        $check = PilotCheck::create(['user_id' => $user->id, 'origin' => 'manual', 'title' => '<Новая>', 'due_on' => '2026-10-27']); // 14 days from local October 13
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 503)->push(['ok' => true, 'result' => ['message_id' => 1]])]);
        Artisan::call('checks:notify');
        $this->assertDatabaseCount('pilot_check_deliveries', 2);
        $this->assertSame(1, DB::table('pilot_check_deliveries')->whereNotNull('sent_at')->count());
        $this->assertFalse(app(\App\Services\PilotCheckReminderQueue::class)->shouldRun());
        Artisan::call('checks:notify');
        Http::assertSentCount(1);
        $this->travel(16)->minutes();
        $this->assertTrue(app(\App\Services\PilotCheckReminderQueue::class)->shouldRun());
        Artisan::call('checks:notify');
        $this->assertSame(2, DB::table('pilot_check_deliveries')->whereNotNull('sent_at')->count());
        $this->assertFalse(app(\App\Services\PilotCheckReminderQueue::class)->shouldRun());
        Artisan::call('checks:notify');
        Http::assertSentCount(2);
        $check->update(['due_on' => '2026-12-30']);
        Artisan::call('checks:notify');
        Http::assertSentCount(2);
    }
    private function credential(User $user): void
    {
        PortalCredential::create(['user_id'=>$user->id, 'portal'=>'rossiya_edu', 'login'=>'test-'.$user->id,
            'password_encrypted'=>Crypt::encryptString('test'), 'status'=>'active']);
    }
    private function certificateSnapshot(User $user): array
    {
        $rows = array_map(fn ($type) => ['title'=>'КВАЛИФ ПРОВЕРКА ... [Квалификационная проверка]',
            'aircraft_type'=>$type, 'valid_from'=>'2026-10-21', 'due_on'=>'2027-10-21',
            'portal_status'=>'Действует', 'confirmation_path'=>'/index/confirm_cr_educ-3'], ['С9Н','СУ95']);
        DB::table('pilot_check_syncs')->updateOrInsert(['user_id'=>$user->id], ['synced_at'=>now(),
            'sync_run_id'=>0, 'certificates'=>json_encode($rows, JSON_UNESCAPED_UNICODE), 'certificates_synced_at'=>now()]);
        return $rows;
    }
    public function test_manual_refresh_runs_before_daily_deadline_without_creating_duplicate_tasks(): void
    {
        $user = $this->admin(); $this->credential($user);
        $task = ParserTask::create(['task_key'=>"checks_refresh:rossiya_edu:user:{$user->id}",
            'user_id'=>$user->id, 'source'=>'rossiya_edu', 'portal'=>'rossiya_edu', 'task_type'=>'checks_refresh',
            'status'=>'scheduled', 'next_run_at'=>now()->addHours(23)]);
        $this->actingAs($user)->postJson('/api/pilot-checks/refresh')->assertStatus(202)->assertJsonPath('status','queued');
        $this->postJson('/api/pilot-checks/refresh')->assertStatus(202);
        $this->assertDatabaseCount('parser_tasks',1);
        $this->assertTrue($task->fresh()->next_run_at->equalTo(now()));
        $this->getJson('/api/pilot-checks')->assertJsonPath('refresh_state','queued');
        $run = SyncRun::create(['parser_task_id'=>$task->id, 'user_id'=>$user->id, 'source'=>'rossiya_edu',
            'task_type'=>'checks_refresh', 'trigger'=>'scheduler', 'status'=>'finished', 'started_at'=>now(), 'finished_at'=>now()]);
        app(ParserTaskScheduler::class)->completeTask($run);
        $this->assertTrue($task->fresh()->next_run_at->equalTo(now()->addHours(24)));
        $this->getJson('/api/pilot-checks')->assertJsonPath('refresh_state','idle');
    }
    public function test_new_daily_snapshot_replaces_expired_dates_and_keeps_manual_checks(): void
    {
        $user = $this->admin();
        InternalApiToken::create(['name'=>'test','token_hash'=>InternalApiToken::hashToken('checks-test'),'is_active'=>true]);
        $old = PilotCheck::create(['user_id'=>$user->id, 'origin'=>'portal', 'source_key'=>'old',
            'title'=>'Квалификационная проверка', 'aircraft_type'=>'СУ95', 'valid_from'=>'2025-10-21', 'due_on'=>'2026-10-21', 'portal_status'=>'Просрочен']);
        $manual = PilotCheck::create(['user_id'=>$user->id, 'origin'=>'manual', 'title'=>'Своя', 'due_on'=>'2026-11-01']);
        $run = SyncRun::create(['user_id'=>$user->id,'source'=>'rossiya_edu','trigger'=>'scheduler','task_type'=>'checks_refresh',
            'status'=>'running','started_at'=>now(),'lock_expires_at'=>now()->addMinutes(15)]);
        $this->withToken('checks-test')->postJson('/api/internal/sync-runs/'.$run->id.'/checks', ['checks'=>[
            ['title'=>'Квалификационная проверка','aircraft_type'=>'СУ95','valid_from'=>'2026-10-21','due_on'=>'2027-10-21','portal_status'=>'Действует']
        ]])->assertOk();
        $this->assertFalse($old->fresh()->is_current);
        $this->assertTrue($manual->fresh()->is_current);
        $this->actingAs($user)->getJson('/api/pilot-checks')->assertJsonCount(2,'checks')->assertJsonMissing(['portal_status'=>'Просрочен']);
        $this->assertDatabaseHas('pilot_checks',['user_id'=>$user->id,'origin'=>'portal','is_current'=>true,'due_on'=>'2027-10-21']);
    }
    public function test_certificate_confirmation_requires_explicit_approval_and_capable_worker(): void
    {
        $user = $this->admin(); $this->credential($user); $rows = $this->certificateSnapshot($user);
        $this->actingAs($user)->getJson('/api/pilot-checks')->assertJsonCount(2,'certificates.rows');
        $this->assertDatabaseCount('parser_tasks',0); // Merely displaying the button cannot confirm anything.
        $version = app(\App\Services\PilotCheckActions::class)->certificates($user->id)['version'];
        $this->postJson('/api/pilot-checks/confirm-certificate',['version'=>$version])->assertUnprocessable();
        $this->postJson('/api/pilot-checks/confirm-certificate',['version'=>str_repeat('0',64),'acknowledged'=>true])->assertConflict();
        $payload = ['version'=>$version,'acknowledged'=>true];
        $this->postJson('/api/pilot-checks/confirm-certificate',$payload)->assertStatus(202);
        $this->postJson('/api/pilot-checks/confirm-certificate',$payload)->assertStatus(202);
        $task = ParserTask::where('task_type','checks_confirm_certificate')->sole();
        $this->assertSame($user->id,$task->payload['approved_by']);
        InternalApiToken::create(['name'=>'test','token_hash'=>InternalApiToken::hashToken('cert-test'),'is_active'=>true]);
        $options = ['source'=>'rossiya_edu','portal'=>'rossiya_edu','locked_by'=>'test','capabilities'=>['typed_tasks_v1']];
        $old = $this->withToken('cert-test')->postJson('/api/internal/parser-jobs/claim',$options)->assertOk()->json('job');
        $this->assertNotSame('checks_confirm_certificate',$old['task_type'] ?? null);
        $options['capabilities'] = ['typed_tasks_v1','checks_v1','check_certificates_v1'];
        $job = $this->postJson('/api/internal/parser-jobs/claim',$options)->assertOk()->json('job');
        $this->assertSame('checks_confirm_certificate',$job['task_type']);
        $this->assertCount(2,$job['task_payload']['certificates']);
        $this->postJson('/api/internal/sync-runs/'.$job['sync_run_id'].'/checks',['checks'=>[], 'certificates'=>[]])->assertOk();
        $this->postJson('/api/internal/sync-runs/'.$job['sync_run_id'].'/finish', ['status'=>'finished', 'stats'=>['checks_found'=>0]])->assertOk();
        $this->actingAs($user)->getJson('/api/pilot-checks')->assertJsonCount(0,'certificates.rows')->assertJsonPath('confirmation_state','completed');
        $this->assertSame('completed',$task->fresh()->status);
        $this->assertNull($task->fresh()->next_run_at);
    }
    public function test_certificate_failure_requires_new_user_request_and_old_workers_preserve_snapshot(): void
    {
        $user=$this->admin(); $this->credential($user); $rows=$this->certificateSnapshot($user);
        $service=app(\App\Services\PilotCheckActions::class);
        $task=$service->queue($user,true,$service->certificates($user->id)['version']);
        $run=SyncRun::create(['parser_task_id'=>$task->id,'user_id'=>$user->id,'source'=>'rossiya_edu','trigger'=>'scheduler',
            'task_type'=>'checks_confirm_certificate','status'=>'failed','started_at'=>now(),'finished_at'=>now(),'error_text'=>'Portal rejected action']);
        app(ParserTaskScheduler::class)->completeTask($run);
        $this->assertSame('failed',$service->state($task->fresh()));
        $this->assertNull($task->fresh()->next_run_at);
        $legacy=SyncRun::create(['user_id'=>$user->id,'source'=>'rossiya_edu','trigger'=>'scheduler','task_type'=>'checks_refresh',
            'status'=>'running','started_at'=>now(),'lock_expires_at'=>now()->addMinutes(15)]);
        InternalApiToken::create(['name'=>'test','token_hash'=>InternalApiToken::hashToken('legacy-checks'),'is_active'=>true]);
        $this->withToken('legacy-checks')->postJson('/api/internal/sync-runs/'.$legacy->id.'/checks',['checks'=>[]])->assertOk();
        $this->assertSame($rows,$service->certificates($user->id)['rows']);
        $this->actingAs(User::create(['status'=>'active','role'=>'user']))->postJson('/api/pilot-checks/refresh')->assertForbidden();
        $this->postJson('/api/pilot-checks/confirm-certificate',['version'=>$service->certificates($user->id)['version'],'acknowledged'=>true])->assertForbidden();
    }

    public function test_expired_certificate_worker_does_not_automatically_repeat_confirmation(): void
    {
        $user = $this->admin(); $this->credential($user); $this->certificateSnapshot($user);
        $actions = app(\App\Services\PilotCheckActions::class);
        $task = $actions->queue($user, true, $actions->certificates($user->id)['version']);
        $options = ['user_id'=>$user->id, 'capabilities'=>['typed_tasks_v1','checks_v1','check_certificates_v1']];
        $job = app(ParserJobService::class)->claim($options);
        $this->assertSame('checks_confirm_certificate', $job['task_type']);
        ParserTask::whereKey($task->id)->update(['lock_expires_at'=>now()->subSecond()]);
        SyncRun::whereKey($job['sync_run_id'])->update(['lock_expires_at'=>now()->subSecond()]);
        $nextJob = app(ParserJobService::class)->claim($options);
        $this->assertNotSame('checks_confirm_certificate', $nextJob['task_type'] ?? null);
        $this->assertSame('failed', $actions->state($task->fresh()));
        $this->assertNull($task->fresh()->next_run_at);
        $this->assertSame('failed', SyncRun::findOrFail($job['sync_run_id'])->status);
    }

}
