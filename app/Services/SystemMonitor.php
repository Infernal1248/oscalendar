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
        $nodes = app(\App\Services\Monitoring\ParserNodeInventory::class)->snapshot();
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
            'notification_backlog' => app(\App\Services\Monitoring\RuntimeHealth::class)->notificationBacklog(),
            // Retry scheduling moves next_run_at; use last success (creation before the first success).
            'stale_roster' => (clone $eligible)->where('task_type', 'roster_refresh')
                ->whereIn('status', ['scheduled', 'running'])
                ->whereRaw('COALESCE(last_success_at, parser_tasks.created_at) <= ?', [now()->subMinutes(config('monitor.stale_sync_minutes'))])->exists(),
        ];
    }

    public function report(string $command): string
    {
        if (in_array($command, ['/load', '/summary'], true)) {
            try {
                $history = app(\App\Services\Monitoring\MonitorHistory::class);
                return $command === '/load' ? $history->loadReport() : $history->summary();
            } catch (\Throwable $exception) {
                return '🔴 История мониторинга недоступна. Проверьте миграции, подключение к БД и права на storage. Данные до установки мониторинга отсутствуют.';
            }
        }
        if (! in_array($command, ['/status', '/parsers', '/queue', '/errors'], true)) {
            return "OSCalendar Monitor — технический бот администратора.\n\n/status — состояние системы\n/parsers — VM и занятость воркеров\n/queue — очередь и зависшие задачи\n/errors — причины сбоев и результаты повторов\n/load — измеренный CPU и запросы за последний час\n/summary — сводка за последние 12 часов\n\nОшибки, сводки и нагрузка — по Красноярску (UTC+7); остальные даты — UTC. CPU PHP приложения не равен проценту нагрузки аккаунта Рег.ру. Бот ничего не изменяет в расписаниях.";
        }
        try {
            $s = $this->snapshot();
        } catch (\Illuminate\Database\QueryException $exception) {
            return '🔴 Бэкенд отвечает, но БД или таблицы мониторинга недоступны. Проверьте подключение к БД, миграции и серверные логи. Статус парсеров сейчас определить нельзя.';
        }
        $header = 'OSCalendar Monitor · '.now()->utc()->format('d.m.Y H:i:s')." UTC\n\n";
        if ($command === '/parsers') {
            $visible = $s['nodes']->where('reserve', false);
            if ($visible->isEmpty()) {
                return $header.'Основные VM для мониторинга отсутствуют. Проверьте MONITOR_NODE_IDS и heartbeat основных парсеров.';
            }
            return $header.$visible->map(fn ($n) =>
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
            return app(\App\Services\Monitoring\SyncFailureReport::class)->render($s);
        }
        $required = $s['nodes']->where('reserve', false);
        $online = $required->where('online', true);
        return $header."Бэкенд и БД отвечают на этот запрос.\nОсновные VM на связи: ".$required->where('online', true)->count().' / '.$required->count().
            "\nЗанято воркеров на VM в сети: ".$online->sum('busy').' / '.$online->sum('max').
            "\nГотовых задач: {$s['due']}\nЗависших блокировок: {$s['stuck']}\nСбоев за 15 минут: {$s['failures']}\nПоследний успешный запуск: ".$this->date($s['last_success']).
            "\nПоследнее успешное обновление расписания: ".$this->date($s['last_roster']).
            "\nПоследняя проверка уведомлений: ".$this->date(Cache::get('monitor:last_check_at')).
            "\n".app(\App\Services\Monitoring\RuntimeHealth::class)->statusText();
    }

    public function checkAlerts(MonitorBotClient $bot): array
    {
        $s = $this->snapshot();
        $conditions = [];
        $required = $s['nodes']->where('reserve', false);
        foreach ($required as $node) {
            $conditions['node:'.$node['id']] = [! $node['online'], 'Связь с VM '.$node['id']];
        }
        $conditions['all_nodes'] = [$required->isNotEmpty() && $required->where('online', true)->isEmpty(), 'Связь со всеми основными VM'];
        $conditions['queue'] = [$s['oldest_due'] && Carbon::parse($s['oldest_due'])->lte(now()->subMinutes(config('monitor.queue_delay_minutes'))), 'Задержка очереди более '.config('monitor.queue_delay_minutes').' мин.'];
        $conditions['stuck'] = [$s['stuck'] > 0, 'Запуски с истёкшей блокировкой'];
        $conditions['repeated_errors'] = [$s['repeated']->isNotEmpty(), 'Повторные сбои: ≥3 на пользователя и тип задачи за 15 минут'];
        $conditions['stale_sync'] = [$s['stale_roster'], 'Есть активные пользователи без успешного обновления расписания более '.config('monitor.stale_sync_minutes').' мин.'];
        $conditions['notifications'] = [$s['notification_backlog'] > 0, 'Telegram-уведомления ожидают отправки более '.config('monitor.notification_delay_minutes').' мин.'];

        foreach (config('monitor.admin_ids') as $chatId) {
            // Reclassification isn't recovery: silently retire an old reserve alarm.
            $reserveKeys = $s['nodes']->where('reserve', true)->map(fn ($n) => $chatId.':node:'.$n['id'])->all();
            if ($reserveKeys) DB::table('monitor_alerts')->whereIn('key', $reserveKeys)->where('active', true)->update(['active' => false]);
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
        return $s;
    }

    private function date($value): string
    {
        return $value ? Carbon::parse($value)->utc()->format('d.m.Y H:i:s').' UTC' : 'нет данных';
    }

}
