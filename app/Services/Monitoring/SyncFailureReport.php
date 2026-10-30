<?php

namespace App\Services\Monitoring;

use App\Models\SyncRun;
use App\Models\SyncLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SyncFailureReport
{
    public function render(array $snapshot): string
    {
        $runs = SyncRun::where('status', 'failed')->with('parserTask:id,status,next_run_at,lock_expires_at')
            ->orderByDesc('finished_at')->orderByDesc('id')->limit(8)
            ->get(['id', 'user_id', 'source', 'parser_task_id', 'task_type', 'error_text', 'finished_at']);
        $taskIds = $runs->pluck('parser_task_id')->filter()->unique()->values();
        $latest = $this->latestByTask($taskIds);
        $successes = $this->latestByTask($taskIds, 'finished');
        $logs = $runs->isEmpty() ? collect() : SyncLog::whereIn('id', SyncLog::whereIn('sync_run_id', $runs->pluck('id'))
            ->where('level', 'error')->selectRaw('MAX(id)')->groupBy('sync_run_id'))
            ->get(['sync_run_id', 'context'])->keyBy('sync_run_id');

        $lines = ['OSCalendar Monitor · '.$this->date(now()), '',
            'Сбоев за последние 15 минут: '.$snapshot['failures'],
            'Повторные сбои за 15 минут (≥3 на пользователя и тип задачи):'];
        $lines[] = $snapshot['repeated']->isEmpty() ? 'нет' : $snapshot['repeated']->map(fn ($r) =>
            'Пользователь #'.$r->user_id.' · '.$this->taskName($r->task_type).' — '.$r->total)->implode("\n");
        $lines[] = '';
        $lines[] = 'История: последние сбои, включая восстановленные задачи';
        if ($runs->isEmpty()) $lines[] = 'Сбоев нет.';
        foreach ($runs as $run) {
            $lines[] = '';
            $lines[] = '#'.$run->id.' · Пользователь #'.$run->user_id.' · '.$this->taskName($run->task_type);
            $lines[] = 'Сбой: '.$this->date($run->finished_at);
            $reason = $this->reason($run->error_text ?? '');
            $type = $logs->get($run->id)?->context['exception'] ?? null;
            // Only known exception names; never echo arbitrary log context or error text.
            if (in_array($type, ['ValueError', 'RuntimeError', 'TypeError', 'KeyError', 'IndexError',
                'ConnectionError', 'ReadTimeout', 'ConnectTimeout', 'TimeoutError', 'HTTPError',
                'SSLError', 'BackendApiError', 'BackendValidationError', 'PermissionError', 'MemoryError'], true)) {
                $reason .= ' ('.$type.')';
            }
            $lines[] = 'Причина: '.$reason;
            $lines[] = $this->retryState($run, $latest->get($run->parser_task_id), $successes->get($run->parser_task_id));
        }
        return implode("\n", $lines);
    }

    private function latestByTask(Collection $taskIds, ?string $status = null): Collection
    {
        if ($taskIds->isEmpty()) return collect();
        $query = SyncRun::whereIn('parser_task_id', $taskIds);
        if ($status !== null) $query->where('status', $status);
        return SyncRun::whereIn('id', $query->selectRaw('MAX(id)')->groupBy('parser_task_id'))
            ->get(['id', 'user_id', 'source', 'task_type', 'parser_task_id', 'status', 'started_at', 'finished_at', 'lock_expires_at'])
            ->keyBy('parser_task_id');
    }

    private function follows(SyncRun $run, ?SyncRun $candidate): bool
    {
        return $candidate && $run->parser_task_id && $candidate->parser_task_id === $run->parser_task_id
            && $candidate->user_id === $run->user_id && $candidate->source === $run->source
            && $candidate->task_type === $run->task_type && $candidate->id > $run->id
            && $run->finished_at && $candidate->started_at?->gte($run->finished_at);
    }

    private function retryState(SyncRun $run, ?SyncRun $latest, ?SyncRun $success): string
    {
        if (! $run->parser_task_id) return '⚪ Задача не указана; результат повторов неизвестен.';
        if ($this->follows($run, $success) && $success->finished_at?->gte($run->finished_at)) {
            $text = '✅ Успешный повтор #'.$success->id.' · '.$this->date($success->finished_at);
            if ($this->follows($success, $latest) && $latest->status === 'failed') {
                $text .= "\n⚠ Позже произошёл новый сбой #".$latest->id.'.';
            }
            return $text;
        }
        $prefix = '';
        if ($this->follows($run, $latest)) {
            if ($latest->status === 'running') {
                return $latest->lock_expires_at && $latest->lock_expires_at->lte(now())
                    ? '⚠ Повтор #'.$latest->id.': срок блокировки истёк, успех не подтверждён.'
                    : '🔄 Повтор #'.$latest->id.' выполняется.';
            }
            if ($latest->status === 'failed') $prefix = '⚠ Повтор #'.$latest->id." тоже завершился сбоем.\n";
            if ($latest->status === 'skipped') $prefix = '⚪ Повтор #'.$latest->id." пропущен; успех не подтверждён.\n";
        }
        $task = $run->parserTask;
        if (! $task) return $prefix.'⚪ Данные задачи недоступны; успешный повтор не подтверждён.';
        return $prefix.match ($task->status) {
            'scheduled' => $task->next_run_at && $task->next_run_at->lte(now())
                ? '⏳ Ожидает очереди с '.$this->date($task->next_run_at)
                : '⏳ Следующий запуск: '.$this->date($task->next_run_at),
            'running' => '🔄 Задача отмечена выполняющейся; результат пока неизвестен.',
            'paused' => '⏸ Повтор приостановлен.',
            'completed' => '⚪ Задача завершена; успешный повтор после этого сбоя не подтверждён.',
            default => '⚪ Успешный повтор пока не подтверждён.',
        };
    }

    private function taskName(?string $type): string
    {
        return match ($type) {
            'flight_details' => 'Детали рейса', 'roster_refresh' => 'Рабочий план',
            'checks_refresh' => 'Допуски', 'flight_time' => 'Налёт', 'acknowledge_roster_changes' => 'Ознакомление с изменениями',
            default => 'Синхронизация',
        };
    }

    private function date($value): string
    {
        return $value ? Carbon::parse($value)->timezone(config('monitor.timezone'))->format('d.m.Y H:i:s').' Красноярск' : 'не назначен';
    }

    private function reason(string $error): string
    {
        $error = mb_strtolower($error);
        return match (true) {
            str_contains($error, 'no flight rows found in workplan details') => 'На странице деталей рабочего плана не найдены строки рейсов.',
            str_contains($error, 'flight segment without starts_at') => 'В данных рейса отсутствует дата вылета.',
            str_contains($error, 'unrecognized flight departure or arrival date') => 'Не удалось распознать дату вылета или прилёта.',
            str_contains($error, 'flight arrival date precedes departure date') => 'Дата прилёта оказалась раньше даты вылета.',
            str_contains($error, 'could not parse detail row') => 'Не удалось разобрать строку деталей рейса.',
            str_contains($error, 'unrecognized workplan page') => 'Не распознана страница рабочего плана.',
            str_contains($error, 'incomplete workplan parsing') => 'Рабочий план разобран не полностью; отмену рейса нельзя подтвердить.',
            str_contains($error, 'multiple ops flights match') => 'В OPS найдено несколько подходящих рейсов; безопасно выбрать экипаж нельзя.',
            str_contains($error, 'worker process exited unexpectedly') => 'Процесс парсера неожиданно завершился.',
            str_contains($error, 'worker lease expired') => 'Истёк срок блокировки: парсер не продлил её вовремя.',
            str_contains($error, 'backend api validation failed') => 'Бэкенд отклонил данные парсера при проверке формата (HTTP 422).',
            str_contains($error, 'portal still requires roster acknowledgement') => 'Портал не подтвердил ознакомление с изменениями.',
            str_contains($error, 'portal did not show the confirmed roster state'), str_contains($error, 'portal kept change markup') => 'После ознакомления портал не вернул подтверждённый рабочий план.',
            str_contains($error, 'invalid workplan request') => 'Некорректные параметры запроса рабочего плана.',
            str_contains($error, 'ssl'), str_contains($error, 'certificate') => 'Ошибка TLS/сертификата.',
            str_contains($error, 'timeout'), str_contains($error, 'timed out') => 'Превышено время ожидания ответа.',
            str_contains($error, 'unauthorized'), str_contains($error, '401'), str_contains($error, 'login') => 'Ошибка авторизации.',
            str_contains($error, 'connection refused'), str_contains($error, 'name resolution'), str_contains($error, 'failed to establish'), str_contains($error, 'connection reset') => 'Не удалось установить или сохранить соединение с порталом/API.',
            str_contains($error, 'ops') => 'Ошибка получения данных OPS.',
            str_contains($error, 'lock'), str_contains($error, 'lease') => 'Ошибка блокировки задачи.',
            default => $this->httpReason($error),
        };
    }

    private function httpReason(string $error): string
    {
        if (preg_match('/\b(?:http(?: error)?|status(?: code)?|server error|api returned)\s*[:=]?\s*([45]\d{2})\b/', $error, $match)
            || preg_match('/\b([45]\d{2}) (?:client|server) error\b/', $error, $match)) {
            return 'Портал/API вернул HTTP '.$match[1].'.';
        }
        return 'Причина пока не распознана; подробности в логах по ID запуска.';
    }
}
