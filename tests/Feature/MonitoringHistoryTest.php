<?php

namespace Tests\Feature;

use App\Models\InternalApiToken;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Monitoring\RuntimeHealth;
use App\Services\Monitoring\MetricStore;
use App\Services\Monitoring\MonitorDigest;
use App\Services\Monitoring\MonitorHistory;
use App\Services\SystemMonitor;
use Illuminate\Support\Facades\{Artisan, DB, File, Http};
use Tests\TestCase;

class MonitoringHistoryTest extends TestCase
{
    private string $metricsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-19 01:00:00', 'UTC'));
        $this->metricsPath = sys_get_temp_dir().'/oscalendar-monitor-'.bin2hex(random_bytes(8));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'monitor.token' => 'test-monitor-token',
            'monitor.admin_ids' => ['12345'], 'monitor.metrics_path' => $this->metricsPath,
            'monitor.metrics_enabled' => true, 'monitor.summary_enabled' => true,
            'monitor.summary_times' => ['08:00', '20:00'], 'monitor.node_ids' => ['vm-1']]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->metricsPath);
        $this->travelBack();
        parent::tearDown();
    }

    private function measurement(?float $cpu = .5): array
    {
        return ['group' => 'http:claim', 'cpu' => $cpu, 'wall' => 1.2, 'memory' => 10485760,
            'http_5xx' => 0, 'http_4xx' => 0, 'errors' => 0, 'warnings' => 1, 'failed' => 0];
    }

    public function test_metric_buckets_add_without_database_queries_and_handle_unavailable_cpu(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $store = app(MetricStore::class);
        $this->assertTrue($store->write($this->measurement()));
        $this->assertTrue($store->write($this->measurement(null)));
        $result = $store->read(now(), now()->addMinute());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $queries);
        $this->assertSame(2, $result['groups']['http:claim']['count']);
        $this->assertSame(1, $result['groups']['http:claim']['cpu_samples']);
        $this->assertSame(.5, $result['groups']['http:claim']['cpu']);
        $this->assertSame(10485760, $result['groups']['http:claim']['memory_max']);
        $this->assertSame(1, $result['buckets']);
    }

    public function test_corrupt_or_unwritable_metrics_never_break_application_work(): void
    {
        mkdir($this->metricsPath, 0700, true);
        file_put_contents($this->metricsPath.'/'.now()->format('YmdHi').'.json', 'broken');
        $this->assertFalse(app(MetricStore::class)->write($this->measurement()));
        $this->assertSame(1, app(MetricStore::class)->read(now(), now()->addMinute())['unreadable']);
        config(['monitor.metrics_path' => $this->metricsPath.'/'.now()->format('YmdHi').'.json/invalid']);
        $this->assertFalse(app(MetricStore::class)->write($this->measurement()));
    }

    public function test_process_metrics_count_request_status_and_logs_once_without_storing_payloads(): void
    {
        $metrics = new \App\Services\Monitoring\ProcessMetrics();
        $request = \Illuminate\Http\Request::create('/api/internal/parser-jobs/claim?secret=private');
        $metrics->request(new \Illuminate\Foundation\Http\Events\RequestHandled($request, response('private', 503)));
        $metrics->log(new \Illuminate\Log\Events\MessageLogged('error', 'password=private', ['token' => 'private']));
        $metrics->finish();
        $metrics->finish();
        $row = app(MetricStore::class)->read(now(), now()->addMinute())['groups']['http:claim'];
        $this->assertSame(1, $row['count']);
        $this->assertSame(1, $row['http_5xx']);
        $this->assertSame(1, $row['errors']);
        $this->assertStringNotContainsString('private', json_encode($row));
        $this->assertSame(1.75, \App\Services\Monitoring\ProcessMetrics::cpu([
            'ru_utime.tv_sec' => 1, 'ru_utime.tv_usec' => 500000, 'ru_stime.tv_sec' => 0, 'ru_stime.tv_usec' => 250000]));
    }

    public function test_http_4xx_breakdown_groups_codes_and_paths_without_storing_actual_urls(): void
    {
        $until = now()->copy();
        $this->travelTo($until->copy()->subMinute());
        foreach ([
            ['/private-token-scanner?password=secret-value', 404],
            ['/another-private-path', 404],
            ['/api/auth/login?token=secret-value', 401],
            ['/api/internal/parser-jobs/claim', 429],
            ['/api/account', 422],
        ] as [$path, $status]) {
            $metrics = new \App\Services\Monitoring\ProcessMetrics();
            $metrics->request(new \Illuminate\Foundation\Http\Events\RequestHandled(
                \Illuminate\Http\Request::create($path), response('private-response', $status)));
            $metrics->finish();
        }
        $this->travelTo($until);
        $report = app(MonitorHistory::class)->loadReport();
        $this->assertStringContainsString('HTTP 4xx по кодам: 404 — 2;', $report);
        $this->assertStringContainsString('Неизвестные адреса (маршрут не найден): 404 — 2.', $report);
        $this->assertStringContainsString('Авторизация /api/auth/*: 401 — 1.', $report);
        $this->assertStringContainsString('Выдача задач /api/internal/parser-jobs/claim: 429 — 1.', $report);
        $this->assertStringContainsString('Аккаунт /api/account*: 422 — 1.', $report);
        $this->assertStringContainsString('Ответов 4xx с разбивкой: 5 / 5.', $report);
        $contents = file_get_contents(glob($this->metricsPath.'/*.json')[0]);
        foreach (['private-token', 'secret-value', 'another-private-path', 'private-response'] as $private) {
            $this->assertStringNotContainsString($private, $contents);
            $this->assertStringNotContainsString($private, $report);
        }
    }

    public function test_legacy_and_new_http_counters_are_combined_with_explicit_breakdown_coverage(): void
    {
        $until = now()->copy();
        $this->travelTo($until->copy()->subMinute());
        $old = array_merge($this->measurement(), ['http_4xx' => 1]);
        app(MetricStore::class)->write($old);
        $legacyReport = app(MonitorHistory::class)->loadReport(1, $until);
        $this->assertStringContainsString('Разбивка HTTP 4xx ещё не накоплена', $legacyReport);
        app(MetricStore::class)->write(array_merge($old, ['http_status' => 403, 'http_address_group' => 'calendar']));
        // Unknown category input must not become a raw URL or unbounded metric label.
        app(MetricStore::class)->write(array_merge($old, ['http_status' => 404, 'http_address_group' => '/private-token']));
        $this->travelTo($until);
        $report = app(MonitorHistory::class)->loadReport();
        $this->assertStringContainsString('HTTP 4xx: 3;', $report);
        $this->assertStringContainsString('Календарная ссылка /api/calendar/*: 403 — 1.', $report);
        $this->assertStringContainsString('Прочие адреса: 404 — 1.', $report);
        $this->assertStringContainsString('Ответов 4xx с разбивкой: 2 / 3.', $report);
        $this->assertStringNotContainsString('private-token', $report);
        $this->assertStringNotContainsString('private-token', file_get_contents(glob($this->metricsPath.'/*.json')[0]));
    }

    public function test_summary_uses_exactly_twelve_hours_and_discloses_missing_history(): void
    {
        $until = now()->copy();
        $user = User::create(['status' => 'active']);
        foreach ([[-13, 'failed'], [-11, 'failed'], [-1, 'finished'], [0, 'failed']] as [$hours, $status]) {
            SyncRun::create(['user_id' => $user->id, 'source' => 'rossiya_edu', 'trigger' => 'scheduler',
                'task_type' => 'roster_refresh', 'status' => $status,
                'started_at' => $until->copy()->addHours($hours)->subMinute(),
                'finished_at' => $until->copy()->addHours($hours)]);
        }
        $this->travelTo($until->copy()->subHour());
        app(MetricStore::class)->write($this->measurement(2.5));
        $this->travelTo($until);
        $report = app(MonitorHistory::class)->summary($until);
        $this->assertStringContainsString('Сводка за 12 часов', $report);
        $this->assertStringContainsString('18.10 20:00–19.10 08:00 Красноярск', $report);
        $this->assertStringContainsString('успешно 1; сбоев 1;', $report);
        $this->assertStringContainsString('Измерено CPU PHP: 2,50 с', $report);
        $this->assertStringContainsString('История: 0 / 720', $report);
        $this->assertStringContainsString('Неполное покрытие', $report);
        $this->assertStringNotContainsString('Средняя измеренная доля', $report);
        $this->assertStringContainsString('Не включают MySQL', $report);
    }

    public function test_capture_records_queue_nodes_and_cron_without_sending_messages(): void
    {
        $snapshot = app(SystemMonitor::class)->snapshot();
        app(MonitorHistory::class)->capture($snapshot);
        app(MonitorHistory::class)->capture($snapshot);
        $this->assertDatabaseCount('monitor_samples', 1);
        $this->assertDatabaseHas('monitor_runtime', ['key' => 'cron', 'seen_at' => now()->toDateTimeString()]);
        $sample = json_decode(DB::table('monitor_samples')->value('payload'), true);
        $this->assertSame('vm-1', $sample['nodes'][0]['id']);
        $this->assertFalse($sample['nodes'][0]['online']);
        Http::assertNothingSent();
    }

    public function test_load_compares_complete_windows_and_shows_cost_per_execution(): void
    {
        $until = now()->copy();
        for ($i = 120; $i >= 1; $i--) {
            $this->travelTo($until->copy()->subMinutes($i));
            app(MetricStore::class)->write($this->measurement($i > 60 ? 1 : .5));
        }
        $this->travelTo($until);
        $report = app(MonitorHistory::class)->loadReport();
        $this->assertStringContainsString('К предыдущему периоду: -50.0%', $report);
        $this->assertStringContainsString('CPU/вып. 0,500 с', $report);
        $this->assertStringContainsString('Средняя измеренная доля одного ядра: 0,83%', $report);
    }

    public function test_retention_drops_old_measurements_and_keeps_recent_data(): void
    {
        mkdir($this->metricsPath, 0700, true);
        $oldFile = $this->metricsPath.'/'.now()->subDays(8)->format('YmdHi').'.json';
        $recentFile = $this->metricsPath.'/'.now()->format('YmdHi').'.json';
        file_put_contents($oldFile, '{}');
        file_put_contents($recentFile, '{}');
        DB::table('monitor_samples')->insert(['sampled_at' => now()->subDays(8), 'payload' => '{}']);
        app(MonitorHistory::class)->capture(app(SystemMonitor::class)->snapshot());
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($recentFile);
        $this->assertDatabaseCount('monitor_samples', 1);
    }

    public function test_digest_schedule_and_deduplication_are_in_krasnoyarsk_time(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['monitor.admin_ids' => ['12345', '67890']]);
        $this->travelTo(now()->subMinute()); // 07:59 Krasnoyarsk.
        app(MonitorDigest::class)->sendDue();
        Http::assertNothingSent();
        $this->travel(1)->minutes(); // 08:00.
        app(MonitorDigest::class)->sendDue();
        $sent = count(Http::recorded());
        $this->assertGreaterThanOrEqual(2, $sent);
        $this->assertSame(2, DB::table('monitor_report_deliveries')->whereNotNull('sent_at')->count());
        app(MonitorDigest::class)->sendDue();
        Http::assertSentCount($sent);
        $this->travel(12)->hours(); // 20:00, new independent slot.
        app(MonitorDigest::class)->sendDue();
        $this->assertSame(4, DB::table('monitor_report_deliveries')->whereNotNull('sent_at')->count());
        $slots = DB::table('monitor_report_deliveries')->distinct()->pluck('slot_at');
        $this->assertContains('2026-10-19 01:00:00', $slots->all());
        $this->assertContains('2026-10-19 13:00:00', $slots->all());
    }

    public function test_digest_runs_after_push_without_an_extra_php_process(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
        $names = array_map(fn ($event) => $event->description, $events);
        $digestIndex = array_search('monitor:send-digest', $names, true);
        $this->assertNotFalse($digestIndex);
        $this->assertGreaterThan(array_search('push:send', $names, true), $digestIndex);
        $this->assertInstanceOf(\Illuminate\Console\Scheduling\CallbackEvent::class, $events[$digestIndex]);
        $this->assertTrue($events[$digestIndex]->withoutOverlapping);
    }

    public function test_summary_hides_offline_reserve_in_legacy_snapshots(): void
    {
        config(['monitor.reserve_node_ids' => ['parser-reserve-5-mac']]);
        DB::table('monitor_samples')->insert(['sampled_at' => now()->subMinute(), 'payload' => json_encode([
            'due' => 0, 'running' => 0, 'stuck' => 0, 'queue_delay' => 0, 'stale_roster' => false,
            'nodes' => [
                ['id' => 'vm-1', 'online' => true, 'busy' => 1, 'max' => 5],
                ['id' => 'parser-reserve-5-mac', 'online' => false, 'busy' => 0, 'max' => 5],
            ],
        ])]);
        $report = app(MonitorHistory::class)->summary();
        $this->assertStringNotContainsString('parser-reserve-5-mac', $report);
        $this->assertStringContainsString('Основных VM в истории: 1.', $report);
        $this->assertStringContainsString('На всех снимках основные VM были на связи.', $report);
    }

    public function test_new_samples_keep_the_reserve_role_used_when_collected(): void
    {
        config(['monitor.reserve_node_ids' => ['parser-reserve-5-mac']]);
        app(MonitorHistory::class)->capture(app(SystemMonitor::class)->snapshot());
        $this->travel(1)->minutes();
        config(['monitor.reserve_node_ids' => []]);
        $report = app(MonitorHistory::class)->summary();
        $this->assertStringNotContainsString('parser-reserve-5-mac', $report);
        $this->assertStringContainsString('⚠ vm-1: без heartbeat', $report);
    }

    public function test_online_reserve_is_hidden_from_history_counts_and_worker_utilization(): void
    {
        config(['monitor.reserve_node_ids' => ['parser-reserve-5-mac']]);
        DB::table('monitor_samples')->insert(['sampled_at' => now()->subMinute(), 'payload' => json_encode([
            'due' => 0, 'running' => 0, 'stuck' => 0, 'queue_delay' => 0, 'stale_roster' => false,
            'nodes' => [
                ['id' => 'vm-1', 'online' => true, 'busy' => 0, 'max' => 5],
                ['id' => 'parser-reserve-5-mac', 'reserve' => false, 'online' => true, 'busy' => 5, 'max' => 5],
            ],
        ])]);
        $report = app(MonitorHistory::class)->summary();
        $this->assertStringNotContainsString('parser-reserve-5-mac', $report);
        $this->assertStringContainsString('Основных VM в истории: 1.', $report);
        $this->assertStringContainsString('Занятость слотов парсеров ср./макс.: 0% / 0%', $report);
    }

    public function test_failed_digest_resumes_at_the_unsent_chunk_on_next_tick(): void
    {
        DB::table('monitor_report_deliveries')->insert(['chat_id' => '12345', 'slot_at' => now(),
            'body' => str_repeat('A', 3500).str_repeat('B', 3500).'C', 'next_chunk' => 0]);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true])->push(['ok' => false], 503)
            ->push(['ok' => true])->push(['ok' => true])]);
        app(MonitorDigest::class)->sendDue();
        $this->assertSame(1, DB::table('monitor_report_deliveries')->value('next_chunk'));
        $this->assertNull(DB::table('monitor_report_deliveries')->value('sent_at'));
        app(MonitorDigest::class)->sendDue();
        $this->assertSame(3, DB::table('monitor_report_deliveries')->value('next_chunk'));
        $this->assertNotNull(DB::table('monitor_report_deliveries')->value('sent_at'));
        $this->assertCount(1, Http::recorded(fn ($r) => $r['text'] === str_repeat('A', 3500)));
    }

    public function test_stale_cron_is_visible_but_parser_heartbeat_does_not_run_a_watchdog(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        InternalApiToken::create(['name' => 'monitor-test', 'token_hash' => InternalApiToken::hashToken('parser-test'), 'is_active' => true]);
        DB::table('monitor_runtime')->where('key', 'installed_at')->update(['seen_at' => now()->subMinutes(10)]);
        $payload = ['node_id' => 'vm-1', 'source' => 'rossiya_edu', 'version' => 'test', 'max_workers' => 5, 'busy_workers' => 0];
        $this->withToken('parser-test')->postJson('/api/internal/parser-nodes/heartbeat', $payload)->assertOk();
        Http::assertNothingSent();
        $this->assertTrue(app(RuntimeHealth::class)->health()['cron_stale']);
        $this->assertStringContainsString('🔴 Последний проход мониторинга cron', app(SystemMonitor::class)->report('/status'));
        DB::table('monitor_runtime')->updateOrInsert(['key' => 'cron'], ['seen_at' => now()]);
        $this->withToken('parser-test')->postJson('/api/internal/parser-nodes/heartbeat', $payload)->assertOk();
        $this->assertFalse(app(RuntimeHealth::class)->health()['cron_stale']);
        Http::assertNothingSent();
    }

    public function test_external_health_endpoint_is_authenticated_and_does_not_expose_secrets(): void
    {
        $this->getJson('/api/internal/monitor/health')->assertUnauthorized();
        InternalApiToken::create(['name' => 'test', 'token_hash' => InternalApiToken::hashToken('health-test'), 'is_active' => true]);
        $this->withToken('health-test')->getJson('/api/internal/monitor/health')->assertOk()
            ->assertJsonPath('cron_stale', false)->assertJsonPath('offline_nodes', 1)->assertJsonPath('ok', false);
        \Illuminate\Support\Facades\Schema::drop('monitor_runtime');
        $response = $this->withToken('health-test')->getJson('/api/internal/monitor/health')->assertStatus(503);
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertStringNotContainsString('test-monitor-token', $response->getContent());
    }
}
