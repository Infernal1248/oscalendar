<?php

namespace App\Services\Monitoring;

use App\Models\SyncRun;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MonitorHistory
{
    public function capture(array $snapshot): void
    {
        $at = now()->utc()->startOfMinute();
        DB::table('monitor_samples')->updateOrInsert(['sampled_at' => $at], ['payload' => json_encode([
            'due' => $snapshot['due'], 'running' => $snapshot['running'], 'stuck' => $snapshot['stuck'],
            'queue_delay' => $snapshot['oldest_due'] ? max(0, Carbon::parse($snapshot['oldest_due'])->diffInSeconds(now())) : 0,
            'stale_roster' => $snapshot['stale_roster'],
            'nodes' => $snapshot['nodes']->values()->all(),
            'host_load' => function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null,
            'disk_free' => function_exists('disk_free_space') ? (@disk_free_space(storage_path()) ?: null) : null,
            'notification_backlog' => $snapshot['notification_backlog'],
        ], JSON_THROW_ON_ERROR)]);
        DB::table('monitor_runtime')->updateOrInsert(['key' => 'cron'], ['seen_at' => now()->utc()]);
        // Once per hour, reuse this process for bounded retention work.
        if ($at->minute === 0) {
            DB::table('monitor_samples')->where('sampled_at', '<', now()->subDays(config('monitor.retention_days')))->delete();
            DB::table('monitor_report_deliveries')->where('slot_at', '<', now()->subDays(config('monitor.retention_days')))->delete();
            app(MetricStore::class)->prune();
        }
    }

    public function loadReport(int $hours = 1, ?CarbonInterface $until = null): string
    {
        $until = ($until ?: now())->copy()->utc()->startOfMinute();
        $from = $until->copy()->subHours($hours);
        $metrics = app(MetricStore::class)->read($from, $until);
        $previous = app(MetricStore::class)->read($from->copy()->subHours($hours), $from);
        $groups = collect($metrics['groups']);
        $cpu = $groups->sum('cpu');
        $count = $groups->sum('count');
        $cpuCount = $groups->sum('cpu_samples');
        $samples = DB::table('monitor_samples')->where('sampled_at', '>=', $from)->where('sampled_at', '<', $until)
            ->orderBy('sampled_at')->get()->map(fn ($row) => json_decode($row->payload, true));
        $header = 'Нагрузка OSCalendar · '.$this->period($from, $until)."\n";
        $lines = [
            'Измерено CPU PHP: '.number_format($cpu, 2, ',', ' ').' с (user + sys).',
            "Учтено HTTP/CLI выполнений: {$count}; CPU доступен для {$cpuCount}.",
            'Минут с метриками: '.$metrics['buckets'].' / '.($hours * 60).'; контрольных снимков: '.$samples->count().'.',
        ];
        $complete = $metrics['buckets'] >= $hours * 60 * .95 && $cpuCount === $count && $count > 0;
        if ($complete) {
            $lines[] = 'Средняя измеренная доля одного ядра: '.number_format($cpu / ($hours * 3600) * 100, 2, ',', ' ').'%. Это не процент Рег.ру.';
        } else {
            $lines[] = '⚠ История неполная или CPU недоступен; отсутствующие данные не означают нулевую нагрузку.';
        }
        if ($complete && $previous['buckets'] >= $hours * 60 * .95
            && collect($previous['groups'])->sum('cpu_samples') === collect($previous['groups'])->sum('count')) {
            $before = collect($previous['groups'])->sum('cpu');
            if ($before > 0) $lines[] = 'К предыдущему периоду: '.sprintf('%+.1f', ($cpu / $before - 1) * 100).'% CPU (суммарный расход; объём работы мог измениться).';
        } else {
            $lines[] = 'Для сравнения нужны два полных периода наблюдений.';
        }
        $labels = ['http:claim' => 'Выдача задач', 'http:node-heartbeat' => 'Heartbeat VM',
            'http:parser-results' => 'Результаты/heartbeat задач', 'http:telegram' => 'Telegram API бэкенда',
            'http:other' => 'Остальные HTTP', 'http:monitor' => 'Внешняя проверка здоровья',
            'cli:schedule:run' => 'Планировщик (включая monitor/push)',
            'cli:schedule:finish' => 'Завершение фоновых задач', 'cli:telegram:notify-roster' => 'Telegram-рассылка',
            'cli:checks:notify' => 'Напоминания о допусках', 'cli:other' => 'Прочие CLI'];
        foreach ($groups->sortByDesc('cpu')->take(8) as $name => $row) {
            $label = $labels[$name] ?? $name;
            $average = $row['count'] ? $row['wall'] / $row['count'] : 0;
            $perCpu = $row['cpu_samples'] ? number_format($row['cpu'] / $row['cpu_samples'], 3, ',', ' ') : 'н/д';
            $lines[] = $label.': '.$row['count'].' выполн.; CPU '.number_format($row['cpu'], 2, ',', ' ').
                ' с; CPU/вып. '.$perCpu.' с; время ср./макс. '.number_format($average, 2).'/'.number_format($row['wall_max'], 2).' с.';
        }
        if ($hours > 1) {
            $lines[] = 'Нагрузка по двухчасовым отрезкам (CPU PHP / выполнений):';
            for ($i = 0; $i < (int) ceil($hours / 2); $i++) {
                $start = $from->copy()->addHours($i * 2)->timezone(config('monitor.timezone'));
                $block = $metrics['blocks'][$i] ?? null;
                $lines[] = $start->format('H:i').'–'.$start->copy()->addHours(2)->format('H:i').': '.
                    ($block ? number_format($block['cpu'], 1).' с / '.$block['count'] : 'нет измерений');
            }
        }
        $lines[] = 'HTTP 5xx: '.$groups->sum('http_5xx').'; HTTP 4xx: '.$groups->sum('http_4xx').
            '; ошибок в логах: '.$groups->sum('errors').'; предупреждений: '.$groups->sum('warnings').
            '; неуспешных CLI-вызовов: '.$groups->sum('failed').'.';
        array_push($lines, ...$this->http4xxBreakdown($groups->all()));
        $lines[] = 'Пик памяти одного PHP-выполнения: '.number_format($groups->max('memory_max') / 1048576, 1).' МиБ (не RAM всего аккаунта).';
        $hostLoads = $samples->map(fn ($s) => $s['host_load'][0] ?? null)->filter(fn ($v) => $v !== null);
        if ($hostLoads->isNotEmpty()) $lines[] = 'Load average среды сервера, ср./макс.: '.number_format($hostLoads->avg(), 2).'/'.number_format($hostLoads->max(), 2).'. Это очередь нагрузки, не CPU% аккаунта; возможны чужие процессы.';
        $free = function_exists('disk_free_space') ? @disk_free_space(storage_path()) : false;
        $lines[] = 'Свободно на файловой системе storage: '.($free === false ? 'н/д' : number_format($free / 1073741824, 1).' ГиБ').' (не остаток квоты аккаунта).';
        $lines[] = 'Сбор: '.(config('monitor.metrics_enabled') ? 'включён' : 'выключен').
            '; хранилище: '.(is_writable(is_dir(config('monitor.metrics_path')) ? config('monitor.metrics_path') : storage_path('app')) ? 'доступно для записи' : '⚠ нет записи').'.';
        if ($metrics['unreadable']) $lines[] = '⚠ Непрочитанных минутных файлов: '.$metrics['unreadable'].'.';
        $lines[] = 'Метрики приблизительные: завершённые PHP-выполнения этого приложения; пропуск при занятой блокировке/ошибке диска. Не включают MySQL, веб-сервер, другие сайты и внешние VM. CPU загрузки PHP до начала скрипта не учитывается.';
        return $header.implode("\n", $lines);
    }

    public function summary(?CarbonInterface $until = null): string
    {
        $until = ($until ?: now())->copy()->utc()->startOfMinute();
        $hours = config('monitor.summary_hours');
        $from = $until->copy()->subHours($hours);
        $runs = SyncRun::where('finished_at', '>=', $from)->where('finished_at', '<', $until);
        $counts = (clone $runs)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $byType = (clone $runs)->selectRaw('task_type, status, COUNT(*) AS total')->groupBy('task_type', 'status')->get();
        $taskLabels = ['roster_refresh' => 'Рабочие планы', 'flight_details' => 'Детали рейсов',
            'checks_refresh' => 'Допуски', 'acknowledge_roster_changes' => 'Ознакомления с изменениями'];
        $lines = ['Сводка за '.$hours.' часов · '.$this->period($from, $until),
            'Синхронизации: успешно '.($counts['finished'] ?? 0).'; сбоев '.($counts['failed'] ?? 0).'; пропущено '.($counts['skipped'] ?? 0).'.'];
        foreach ($byType->groupBy('task_type') as $type => $rows) {
            $lines[] = ($taskLabels[$type] ?? ($type ?: 'без типа')).': успешно '.$rows->where('status', 'finished')->sum('total').'; сбоев '.$rows->where('status', 'failed')->sum('total').'.';
        }
        $samples = DB::table('monitor_samples')->where('sampled_at', '>=', $from)->where('sampled_at', '<', $until)
            ->orderBy('sampled_at')->get()->map(fn ($r) => json_decode($r->payload, true));
        $inventory = app(ParserNodeInventory::class);
        $samples = $samples->map(function ($sample) use ($inventory) {
            // Hide currently configured reserves even in older snapshots, and
            // preserve the reserve role of snapshots collected before a role change.
            $sample['nodes'] = array_values(array_filter($sample['nodes'], fn ($node) =>
                ! ($node['reserve'] ?? false) && ! $inventory->isReserve($node['id'])));
            return $sample;
        });
        $expected = $hours * 60;
        $lines[] = 'История: '.$samples->count()." / {$expected} минутных снимков. ".($samples->count() >= $expected
            ? 'Есть снимок каждой минуты; короткие события между снимками всё равно возможны.'
            : '⚠ Неполное покрытие: работу в пробелах подтвердить нельзя.');
        if ($samples->isNotEmpty()) {
            $lines[] = 'Максимум готовых задач: '.$samples->max('due').'; задержка очереди: '.round($samples->max('queue_delay') / 60, 1).' мин.';
            $lines[] = 'Снимков с истёкшими блокировками: '.$samples->filter(fn ($s) => $s['stuck'] > 0)->count().
                '; с устаревшим рабочим планом: '.$samples->where('stale_roster', true)->count().
                '; с задержкой Telegram: '.$samples->filter(fn ($s) => ($s['notification_backlog'] ?? 0) > 0)->count().'.';
            $offline = [];
            $seen = [];
            foreach ($samples as $sample) {
                foreach ($sample['nodes'] as $node) {
                    $seen[$node['id']] = true;
                    if (! $node['online']) $offline[$node['id']] = ($offline[$node['id']] ?? 0) + 1;
                }
            }
            $lines[] = $seen ? 'Основных VM в истории: '.count($seen).'.' : 'Основных VM в истории нет.';
            foreach ($offline as $id => $count) $lines[] = '⚠ '.$id.': без heartbeat в '.$count.' снимках (~'.$count.' мин. наблюдаемого простоя).';
            if ($seen && ! $offline) $lines[] = 'На всех снимках основные VM были на связи.';
            $utilization = $samples->map(function ($sample) {
                $online = collect($sample['nodes'])->where('online', true);
                $slots = $online->sum('max');
                return $slots > 0 ? $online->sum('busy') / $slots * 100 : null;
            })->filter(fn ($value) => $value !== null);
            if ($utilization->isNotEmpty()) $lines[] = 'Занятость слотов парсеров ср./макс.: '.
                round($utilization->avg(), 1).'% / '.round($utilization->max(), 1).'% (не CPU VM).';
        }
        $errors = (clone $runs)->where('status', 'failed')->orderByDesc('finished_at')->limit(5)->get(['id', 'task_type']);
        if ($errors->isNotEmpty()) $lines[] = 'Последние сбои: '.$errors->map(fn ($r) => '#'.$r->id.' '.$r->task_type)->implode(', ').'. Подробности: /errors.';
        $lines[] = app(RuntimeHealth::class)->statusText();
        $lines[] = '';
        $lines[] = $this->loadReport($hours, $until);
        return implode("\n", $lines);
    }

    private function period(CarbonInterface $from, CarbonInterface $until): string
    {
        return $from->copy()->timezone(config('monitor.timezone'))->format('d.m H:i').'–'.
            $until->copy()->timezone(config('monitor.timezone'))->format('d.m H:i').' Красноярск (UTC+7)';
    }

    private function http4xxBreakdown(array $groups): array
    {
        $total = 0;
        $byCode = [];
        $byAddress = [];
        $labels = HttpAddressGroups::labels();
        foreach ($groups as $row) {
            $total += $row['http_4xx'] ?? 0;
            foreach ($row as $key => $count) {
                if (! preg_match('/\Ahttp_4xx:(4\d{2}):([a-z-]+)\z/', $key, $match)) continue;
                $code = $match[1];
                $address = isset($labels[$match[2]]) ? $match[2] : 'other';
                $byCode[$code] = ($byCode[$code] ?? 0) + $count;
                $byAddress[$address][$code] = ($byAddress[$address][$code] ?? 0) + $count;
            }
        }
        if (! $total) return [];
        if (! $byCode) return ['Разбивка HTTP 4xx ещё не накоплена: она собирается только после обновления.'];
        $lines = ['HTTP 4xx по кодам: '.$this->statusCounts($byCode), 'HTTP 4xx по группам адресов:'];
        uasort($byAddress, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        foreach (array_slice($byAddress, 0, 8, true) as $address => $counts) $lines[] = $labels[$address].': '.$this->statusCounts($counts);
        if (count($byAddress) > 8) $lines[] = 'Остальные группы: '.array_sum(array_map('array_sum', array_slice($byAddress, 8, null, true))).' ответов.';
        $detailed = array_sum($byCode);
        $lines[] = "Ответов 4xx с разбивкой: {$detailed} / {$total}.";
        return $lines;
    }

    private function statusCounts(array $counts): string
    {
        arsort($counts);
        $parts = [];
        foreach (array_slice($counts, 0, 8, true) as $code => $count) $parts[] = $code.' — '.$count;
        if (count($counts) > 8) $parts[] = 'прочие коды — '.array_sum(array_slice($counts, 8, null, true));
        return implode('; ', $parts).'.';
    }
}
