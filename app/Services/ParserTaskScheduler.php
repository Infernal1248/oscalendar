<?php

namespace App\Services;

use App\Models\ParserTask;
use App\Models\PortalCredential;
use App\Models\RosterItem;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ParserTaskScheduler
{
    public function ensureRosterTasks(string $source, string $portal): void
    {
        PortalCredential::query()
            ->select('portal_credentials.*')
            ->join('users', 'users.id', '=', 'portal_credentials.user_id')
            ->where('users.status', 'active')
            ->where('portal_credentials.status', 'active')
            ->where('portal_credentials.portal', $portal)
            ->whereNotExists(function ($query) use ($source) {
                $query->select(DB::raw(1))
                    ->from('parser_tasks')
                    ->whereColumn('parser_tasks.user_id', 'portal_credentials.user_id')
                    ->where('parser_tasks.source', $source)
                    ->where('parser_tasks.task_type', 'roster_refresh');
            })
            ->orderBy('portal_credentials.id')
            ->limit(100)
            ->get()
            ->each(function (PortalCredential $credential) use ($source, $portal) {
                ParserTask::query()->firstOrCreate(
                    ['task_key' => $this->rosterTaskKey($credential->user_id, $source)],
                    [
                        'user_id' => $credential->user_id,
                        'source' => $source,
                        'portal' => $portal,
                        'task_type' => 'roster_refresh',
                        'status' => 'scheduled',
                        'priority' => 70,
                        'next_run_at' => now(),
                    ]
                );
            });
    }

    public function scheduleFlightDetails(RosterItem $item, bool $force = false): ?ParserTask
    {
        $eligible = $item->is_actual
            && ! $item->is_removed_from_source
            && $item->source_external_id
            && $item->source_request_raw
            && $item->starts_at;
        $taskKey = $this->flightTaskKey($item);
        $task = ParserTask::query()->where('task_key', $taskKey)->first();

        if (! $eligible) {
            if ($task && $task->status !== 'running') {
                $task->forceFill(['status' => 'completed', 'next_run_at' => null])->save();
            }
            return $task;
        }

        [$priority, $nextRunAt] = $this->flightSchedule($item, now());
        if ($nextRunAt === null) {
            if ($task && $task->status !== 'running') {
                $task->forceFill(['status' => 'completed', 'next_run_at' => null])->save();
            }
            return $task;
        }

        if (! $task) {
            return ParserTask::query()->create([
                'task_key' => $taskKey,
                'user_id' => $item->user_id,
                'roster_item_id' => $item->id,
                'source' => $item->source,
                'portal' => $item->source,
                'task_type' => 'flight_details',
                'status' => 'scheduled',
                'priority' => $priority,
                'next_run_at' => now(),
            ]);
        }

        $attributes = ['priority' => $priority];
        if ($force) {
            if ($task->status === 'running') {
                $attributes['refresh_requested'] = true;
            } else {
                $attributes['status'] = 'scheduled';
                $attributes['next_run_at'] = now();
            }
        } elseif ($task->status === 'completed') {
            $attributes['status'] = 'scheduled';
            $attributes['next_run_at'] = $nextRunAt;
        }
        $task->forceFill($attributes)->save();

        return $task;
    }

    public function completeTask(SyncRun $syncRun): void
    {
        if (! $syncRun->parser_task_id) {
            return;
        }

        $task = ParserTask::query()->lockForUpdate()->find($syncRun->parser_task_id);
        if (! $task) {
            return;
        }

        $finishedAt = $syncRun->finished_at ?: now();
        $attributes = [
            'locked_at' => null,
            'lock_expires_at' => null,
            'locked_by' => null,
            'last_finished_at' => $finishedAt,
        ];

        if ($syncRun->status === 'finished') {
            $attributes['last_success_at'] = $finishedAt;
            $attributes['last_error_at'] = null;
            $attributes['last_error_text'] = null;

            if ($task->task_type === 'roster_refresh') {
                $attributes['status'] = 'scheduled';
                $attributes['next_run_at'] = $this->nextFromTaskStart(
                    $task,
                    $finishedAt,
                    max(1, (int) config('parser.roster_interval_minutes', 60)) * 60
                );
            } else {
                $item = $task->rosterItem()->first();
                [$priority, $nextRunAt] = $item
                    ? $this->flightSchedule($item, $finishedAt)
                    : [0, null];
                $attributes['priority'] = $priority;
                if ($task->refresh_requested) {
                    $nextRunAt = $finishedAt;
                } elseif ($nextRunAt) {
                    $intervalSeconds = (int) $finishedAt->diffInSeconds($nextRunAt);
                    $nextRunAt = $this->nextFromTaskStart($task, $finishedAt, $intervalSeconds);
                }
                $attributes['refresh_requested'] = false;
                $attributes['status'] = $nextRunAt ? 'scheduled' : 'completed';
                $attributes['next_run_at'] = $nextRunAt;
            }
        } else {
            $attributes['last_error_at'] = $finishedAt;
            $attributes['last_error_text'] = $syncRun->error_text;
            $item = $task->task_type === 'flight_details' ? $task->rosterItem()->first() : null;
            $isNoLongerEligible = $item && (
                ! $item->is_actual
                || $item->is_removed_from_source
                || $this->flightSchedule($item, $finishedAt)[1] === null
            );
            $attributes['status'] = $isNoLongerEligible ? 'completed' : 'scheduled';
            $attributes['next_run_at'] = $isNoLongerEligible
                ? null
                : ($task->refresh_requested
                    ? $finishedAt
                    : $finishedAt->copy()->addMinutes(max(1, (int) config('parser.retry_interval_minutes', 10))));
            $attributes['refresh_requested'] = false;
        }

        $task->forceFill($attributes)->save();
    }

    public function flightSchedule(RosterItem $item, Carbon $now): array
    {
        $start = $item->starts_at;
        $end = $item->ends_at;

        if ($end && $now->greaterThan($end)) {
            if ($now->greaterThan($end->copy()->addDay())) {
                return [0, null];
            }
            return [90, $now->copy()->addHour()];
        }

        if (! $end && $start && $now->greaterThan($start->copy()->addDay())) {
            return [0, null];
        }

        $secondsUntilStart = $start ? $now->diffInSeconds($start, false) : PHP_INT_MAX;
        if ($secondsUntilStart <= 86400) {
            return [100, $now->copy()->addMinutes(15)];
        }
        if ($secondsUntilStart <= 3 * 86400) {
            return [80, $now->copy()->addHour()];
        }
        if ($secondsUntilStart <= 4 * 86400) {
            return [60, $now->copy()->addHours(3)];
        }
        if ($secondsUntilStart <= 7 * 86400) {
            return [40, $now->copy()->addHours(6)];
        }

        return [20, $now->copy()->addHours(12)];
    }

    private function rosterTaskKey(int $userId, string $source): string
    {
        return "roster_refresh:{$source}:user:{$userId}";
    }

    private function flightTaskKey(RosterItem $item): string
    {
        return "flight_details:{$item->source}:roster:{$item->id}";
    }

    private function nextFromTaskStart(ParserTask $task, Carbon $finishedAt, int $intervalSeconds): Carbon
    {
        $nextRunAt = ($task->last_started_at ?: $finishedAt)->copy()->addSeconds($intervalSeconds);
        return $nextRunAt->lessThan($finishedAt) ? $finishedAt : $nextRunAt;
    }
}
