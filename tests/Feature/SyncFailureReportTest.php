<?php

namespace Tests\Feature;

use App\Models\{ParserTask, SyncLog, SyncRun, User};
use App\Services\Monitoring\SyncFailureReport;
use Illuminate\Support\Facades\{Artisan, DB, Http};
use Tests\TestCase;

class SyncFailureReportTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-23 06:00:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
        $this->user = User::create(['status' => 'active']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function task(): ParserTask
    {
        return ParserTask::create(['user_id' => $this->user->id, 'task_key' => bin2hex(random_bytes(8)),
            'source' => 'rossiya_edu', 'portal' => 'rossiya_edu', 'task_type' => 'flight_details',
            'status' => 'scheduled', 'next_run_at' => now()->addMinutes(10)]);
    }

    private function makeRun(?ParserTask $task, string $status = 'failed', array $attributes = []): SyncRun
    {
        return SyncRun::create(array_merge(['user_id' => $this->user->id, 'parser_task_id' => $task?->id,
            'source' => 'rossiya_edu', 'task_type' => 'flight_details', 'trigger' => 'scheduler', 'status' => $status,
            'started_at' => now()->subMinutes(31), 'finished_at' => now()->subMinutes(30),
            'error_text' => 'No flight rows found in workplan details for private-id'], $attributes));
    }

    private function report(): string
    {
        return app(SyncFailureReport::class)->render(['failures' => 0, 'repeated' => collect()]);
    }

    public function test_report_shows_safe_reason_local_time_and_successful_retry_of_same_task(): void
    {
        $task = $this->task();
        $failure = $this->makeRun($task);
        $success = $this->makeRun($task, 'finished', ['started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(19)]);
        SyncLog::create(['sync_run_id' => $failure->id, 'level' => 'error', 'message' => 'password=private',
            'context' => ['exception' => 'ValueError', 'token' => 'private']]);

        $report = $this->report();

        $this->assertStringContainsString('На странице деталей рабочего плана не найдены строки рейсов. (ValueError)', $report);
        $this->assertStringContainsString('✅ Успешный повтор #'.$success->id, $report);
        $this->assertStringContainsString('23.10.2026 13:00:00 Красноярск', $report);
        $this->assertStringContainsString('История: последние сбои, включая восстановленные задачи', $report);
        $this->assertStringNotContainsString('private', $report);
        $this->assertStringNotContainsString('flight_details', $report);
    }

    public function test_success_of_another_flight_for_same_user_does_not_mark_failure_recovered(): void
    {
        $this->makeRun($this->task());
        $this->makeRun($this->task(), 'finished', ['started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(19)]);
        $report = $this->report();
        $this->assertStringNotContainsString('✅ Успешный повтор', $report);
        $this->assertStringContainsString('⏳ Следующий запуск:', $report);
    }

    public function test_later_success_cannot_hide_a_new_failure(): void
    {
        $task = $this->task();
        $this->makeRun($task);
        $success = $this->makeRun($task, 'finished', ['started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(19)]);
        $latestFailure = $this->makeRun($task, 'failed', ['started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9)]);
        $report = $this->report();
        $this->assertStringContainsString('✅ Успешный повтор #'.$success->id, $report);
        $this->assertStringContainsString('⚠ Позже произошёл новый сбой #'.$latestFailure->id, $report);
        $this->assertSame(1, substr_count($report, '✅ Успешный повтор'));
    }

    public function test_running_retry_and_lease_expiry_are_shown_without_claiming_recovery(): void
    {
        $task = $this->task();
        $this->makeRun($task);
        $retry = $this->makeRun($task, 'running', ['started_at' => now()->subMinute(), 'finished_at' => null, 'lock_expires_at' => now()->addMinutes(10)]);
        $this->assertStringContainsString('🔄 Повтор #'.$retry->id.' выполняется.', $this->report());
        $retry->update(['lock_expires_at' => now()->subSecond()]);
        $this->assertStringContainsString('срок блокировки истёк', $this->report());
        $this->assertStringNotContainsString('✅ Успешный повтор', $this->report());
    }

    public function test_missing_task_and_unknown_error_do_not_expose_arbitrary_error_or_log_content(): void
    {
        $failure = $this->makeRun(null, 'failed', ['error_text' => 'https://secret.example/?password=private-token <html>private</html>']);
        SyncLog::create(['sync_run_id' => $failure->id, 'level' => 'error', 'message' => 'private',
            'context' => ['exception' => 'private-token', 'password' => 'private-token']]);
        $report = $this->report();
        $this->assertStringContainsString('Причина пока не распознана', $report);
        $this->assertStringContainsString('Задача не указана; результат повторов неизвестен', $report);
        $this->assertStringNotContainsString('private', $report);
        $this->assertStringNotContainsString('secret.example', $report);
        Http::assertNothingSent();
    }

    public function test_overlapping_success_is_not_treated_as_a_retry_after_failure(): void
    {
        $task = $this->task();
        $this->makeRun($task);
        $this->makeRun($task, 'finished', ['started_at' => now()->subMinutes(40), 'finished_at' => now()->subMinutes(20)]);
        $this->assertStringNotContainsString('✅ Успешный повтор', $this->report());
    }

    public function test_report_loads_retry_state_in_batches(): void
    {
        for ($i = 0; $i < 8; $i++) $this->makeRun($this->task());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->report();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(5, $queries); // Failures, tasks, latest runs, successes, error metadata.
    }
}
