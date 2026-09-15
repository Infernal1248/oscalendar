<?php

namespace App\Services;

use App\Models\ParserTask;
use App\Models\SyncRun;
use App\Services\Telegram\MonitorBotClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemMonitor
{
    public function snapshot(): array
    {
        $registered = DB::table('parser_nodes')->orderBy('node_id')->get()->keyBy('node_id');
        $ids = config('monitor.node_ids') ?: $registered->keys()->all();
        $nodes = collect($ids)->map(function ($id) use ($registered) {
            $node = $registered->get($id);
            return [
                'id' => $id,
                'online' => $node && Carbon::parse($node->last_seen_at)->gte(now()->subSeconds(config('monitor.offline_seconds'))),
                'seen' => $node?->last_seen_at,
                'busy' => $node?->busy_workers ?? 0,
                'max' => $node?->max_workers ?? 0,
                'version' => $node?->version ?? 'неизвестна',
            ];
        });
        $eligible = ParserTask::whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('portal_credentials')
                ->whereColumn('portal_credentials.user_id', 'parser_tasks.user_id')
                ->whereColumn('portal_credentials.portal', 'parser_tasks.portal')
                ->where('portal_credentials.status', 'active'));
        $due = (clone $eligible)->where('status', 'scheduled')->where('next_run_at', '<=', now());
        $oldest = (clone $due)->min('next_run_at');
        $recentFailures = SyncRun::where('status', 'failed')->where('finished_at', '>=', now()->subMinutes(15));

        return [
            'nodes' => $nodes,
            'due' => (clone $due)->count(),
            'oldest_due' => $oldest,
            'running' => SyncRun::where('status', 'running')->count(),
            'stuck' => SyncRun::where('status', 'running')->where('lock_expires_at', '<', now())->count(),
            'failures' => (clone $recentFailures)->count(),
            'repeated' => (clone $recentFailures)->selectRaw('user_id, task_type, COUNT(*) AS total')
                ->groupBy('user_id', 'task_type')->havingRaw('COUNT(*) >= 3')->get(),
            'last_success' => SyncRun::where('status', 'finished')->max('finished_at'),
            'last_roster' => ParserTask::where('task_type', 'roster_refresh')->max('last_success_at'),
            // Retry scheduling moves next_run_at; use last success (creation before the first success).
            'stale_roster' => (clone $eligible)->where('task_type', 'roster_refresh')
                ->whereIn('status', ['scheduled', 'running'])
                ->whereRaw('COALESCE(last_success_at, parser_tasks.created_at) <= ?', [now()->subMinutes(config('monitor.stale_sync_minutes'))])->exists(),
        ];
    }

    public function report(string $command): string
    {
        if (! in_array($command, ['/status', '/parsers', '/queue', '/errors'], true)) {
            return "OSCalendar Monitor — технический бот администратора.\n\n/status — состояние системы\n/parsers — VM и занятость воркеров\n/queue — очередь и зависшие задачи\n/errors — последние сбои\n\nВсе даты — UTC. Загрузка означает занятые слоты воркеров, не CPU. Бот ничего не изменяет в расписаниях.";
        }
        try {
            $s = $this->snapshot();
        } catch (\Illuminate\Database\QueryException $exception) {
            return '🔴 Бэкенд отвечает, но БД или таблицы мониторинга недоступны. Проверьте подключение к БД, миграции и серверные логи. Статус парсеров сейчас определить нельзя.';
        }
        $header = 'OSCalendar Monitor · '.now()->utc()->format('d.m.Y H:i:s')." UTC\n\n";
        if ($command === '/parsers') {
            if ($s['nodes']->isEmpty()) {
                return $header.'VM ещё не зарегистрированы. Включите MONITOR_HEARTBEAT_ENABLED на парсерах.';
            }
            return $header.$s['nodes']->map(fn ($n) =>
                ($n['online'] ? '🟢 ' : '🔴 ').$n['id'].($n['online'] ? ' — на связи' : ' — нет heartbeat').
                "\nПоследний сигнал: ".$this->date($n['seen']).
                "\nВоркеры: ".($n['online'] ? "{$n['busy']} / {$n['max']}" : 'неизвестно (VM не на связи)').
                "\nВерсия: ".$n['version']
            )->implode("\n\n");
        }
        if ($command === '/queue') {
            return $header."Готовы к выполнению: {$s['due']}\nСамая ранняя задача: ".$this->date($s['oldest_due']).
                "\nЗапуски со статусом running: {$s['running']}\nИз них с истёкшей блокировкой: {$s['stuck']}\n\nБудущие задачи не включены в очередь готовых.";
        }
        if ($command === '/errors') {
            $runs = SyncRun::where('status', 'failed')->orderByDesc('finished_at')->limit(8)->get();
            $errors = $runs->map(fn ($r) => "#{$r->id} · user_id={$r->user_id} · {$r->task_type}\n".
                $this->date($r->finished_at).' — '.$this->errorCategory($r->error_text ?? ''))->implode("\n\n");
            $repeated = $s['repeated']->map(fn ($r) => "user_id={$r->user_id}, {$r->task_type}: {$r->total}")->implode("\n");
            return $header."Сбоев за 15 минут: {$s['failures']}\nПовторные сбои (≥3 на пользователя и тип задачи):\n".
                ($repeated ?: 'нет')."\n\nПоследние неуспешные запуски:\n".($errors ?: 'нет').
                "\n\nПоказаны категории ошибок. Подробности — в логах по ID запуска; пароли и содержимое запросов сюда не отправляются.";
        }
        $online = $s['nodes']->where('online', true);
        return $header."Бэкенд и БД отвечают на этот запрос.\nVM на связи: ".$online->count().' / '.$s['nodes']->count().
            "\nЗанято воркеров на VM в сети: ".$online->sum('busy').' / '.$online->sum('max').
            "\nГотовых задач: {$s['due']}\nЗависших блокировок: {$s['stuck']}\nСбоев за 15 минут: {$s['failures']}\nПоследний успешный запуск: ".$this->date($s['last_success']).
            "\nПоследнее успешное обновление расписания: ".$this->date($s['last_roster']).
            "\nПоследняя проверка уведомлений: ".$this->date(Cache::get('monitor:last_check_at')).
            "\n\nДоступность портала оценивается по результатам синхронизации; прямой проверки портала нет. Если сам хостинг отключится, этот бот также не сможет ответить.";
    }

    public function checkAlerts(MonitorBotClient $bot): void
    {
        $s = $this->snapshot();
        $conditions = [];
        foreach ($s['nodes'] as $node) {
            $conditions['node:'.$node['id']] = [! $node['online'], 'Связь с VM '.$node['id']];
        }
        $conditions['all_nodes'] = [$s['nodes']->isNotEmpty() && $s['nodes']->where('online', true)->isEmpty(), 'Связь со всеми VM'];
        $conditions['queue'] = [$s['oldest_due'] && Carbon::parse($s['oldest_due'])->lte(now()->subMinutes(config('monitor.queue_delay_minutes'))), 'Задержка очереди более '.config('monitor.queue_delay_minutes').' мин.'];
        $conditions['stuck'] = [$s['stuck'] > 0, 'Запуски с истёкшей блокировкой'];
        $conditions['repeated_errors'] = [$s['repeated']->isNotEmpty(), 'Повторные сбои: ≥3 на пользователя и тип задачи за 15 минут'];
        $conditions['stale_sync'] = [$s['stale_roster'], 'Есть активные пользователи без успешного обновления расписания более '.config('monitor.stale_sync_minutes').' мин.'];

        foreach (config('monitor.admin_ids') as $chatId) {
            foreach ($conditions as $name => [$active, $label]) {
                $key = $chatId.':'.$name;
                $previous = DB::table('monitor_alerts')->where('key', $key)->first();
                if ((bool) ($previous?->active ?? false) === (bool) $active) {
                    continue;
                }
                // Store only after successful delivery; failed sends retry on the next cron tick.
                try {
                    $bot->send((string) $chatId, ($active ? '🔴 Проблема: ' : '🟢 Восстановлено / больше не наблюдается: ').$label.
                        "\n".now()->utc()->format('d.m.Y H:i:s')." UTC\nПодробности: /status /parsers /queue /errors");
                } catch (\RuntimeException $exception) {
                    continue;
                }
                DB::table('monitor_alerts')->updateOrInsert(['key' => $key], ['active' => (bool) $active, 'sent_at' => now()]);
            }
        }
        Cache::forever('monitor:last_check_at', now()->toDateTimeString());
    }

    private function date($value): string
    {
        return $value ? Carbon::parse($value)->utc()->format('d.m.Y H:i:s').' UTC' : 'нет данных';
    }

    private function errorCategory(string $error): string
    {
        $error = mb_strtolower($error);
        return match (true) {
            str_contains($error, 'ssl'), str_contains($error, 'certificate') => 'Ошибка TLS/сертификата',
            str_contains($error, 'timeout'), str_contains($error, 'timed out') => 'Превышено время ожидания',
            str_contains($error, 'unauthorized'), str_contains($error, '401'), str_contains($error, 'login') => 'Ошибка авторизации',
            str_contains($error, 'ops') => 'Ошибка получения данных OPS',
            str_contains($error, 'lock'), str_contains($error, 'lease') => 'Ошибка блокировки задачи',
            default => 'Сбой синхронизации (подробности в логах)',
        };
    }
}
