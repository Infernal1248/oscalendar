<?php

namespace App\Services;

use App\Models\ParserTask;
use App\Models\PortalCredential;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParserJobService
{
    public function __construct(private ParserTaskScheduler $scheduler)
    {
    }

    public function claim(array $options): ?array
    {
        return DB::transaction(function () use ($options) {
            $source = $options['source'] ?? 'rossiya_edu';
            $portal = $options['portal'] ?? $source;
            $lockedBy = $options['locked_by'] ?? (gethostname() ?: 'parser');
            $lockSeconds = (int) ($options['lock_seconds'] ?? 900);
            $userId = $options['user_id'] ?? null;
            $now = now();

            $this->scheduler->ensureRosterTasks($source, $portal);
            $this->releaseExpiredTasks($source, $now);

            $claimed = $this->claimDueTask($source, $portal, $lockedBy, $lockSeconds, $now, $userId);
            if (! $claimed) {
                Log::info('Parser job service found no due task', [
                    'source' => $source,
                    'portal' => $portal,
                    'user_id' => $userId,
                ]);
                return null;
            }

            [$task, $credential, $syncRun, $taskPayload] = $claimed;

            return [
                'sync_run_id' => $syncRun->id,
                'task_id' => $task->id,
                'task_type' => $task->task_type,
                'task_payload' => $taskPayload,
                'user_id' => $task->user_id,
                'source' => $task->source,
                'portal' => $credential->portal,
                'login' => $credential->login,
                'password' => Crypt::decryptString($credential->password_encrypted),
                'attempt' => $syncRun->attempt,
                'locked_by' => $syncRun->locked_by,
                'lock_expires_at' => optional($syncRun->lock_expires_at)->toIso8601String(),
            ];
        }, 3);
    }

    public function heartbeat(SyncRun $syncRun, array $data): SyncRun
    {
        $lockSeconds = (int) ($data['lock_seconds'] ?? 900);
        $now = now();
        $lockedBy = $data['locked_by'] ?? $syncRun->locked_by;

        return DB::transaction(function () use ($syncRun, $lockSeconds, $now, $lockedBy) {
            $syncRun = SyncRun::query()->lockForUpdate()->findOrFail($syncRun->id);
            $syncRun->forceFill([
                'heartbeat_at' => $now,
                'lock_expires_at' => $now->copy()->addSeconds($lockSeconds),
                'locked_by' => $lockedBy,
                'worker_id' => $syncRun->worker_id ?: $lockedBy,
            ])->save();

            if ($syncRun->parser_task_id) {
                ParserTask::query()
                    ->whereKey($syncRun->parser_task_id)
                    ->where('status', 'running')
                    ->update([
                        'lock_expires_at' => $now->copy()->addSeconds($lockSeconds),
                        'locked_by' => $lockedBy,
                    ]);
            }

            return $syncRun;
        });
    }

    private function claimDueTask(
        string $source,
        string $portal,
        string $lockedBy,
        int $lockSeconds,
        Carbon $now,
        ?int $userId
    ): ?array {
        $excludedUsers = [];
        $maxPerUser = max(1, (int) config('parser.max_concurrent_per_user', 3));

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $query = ParserTask::query()
                ->select('parser_tasks.*')
                ->join('portal_credentials', function ($join) {
                    $join->on('portal_credentials.user_id', '=', 'parser_tasks.user_id')
                        ->on('portal_credentials.portal', '=', 'parser_tasks.portal');
                })
                ->join('users', 'users.id', '=', 'parser_tasks.user_id')
                ->where('parser_tasks.source', $source)
                ->where('parser_tasks.portal', $portal)
                ->where('parser_tasks.status', 'scheduled')
                ->where('parser_tasks.next_run_at', '<=', $now)
                ->where('portal_credentials.status', 'active')
                ->where('users.status', 'active')
                ->orderByDesc('parser_tasks.priority')
                ->orderBy('parser_tasks.next_run_at')
                ->orderBy('parser_tasks.id');

            if ($userId) {
                $query->where('parser_tasks.user_id', $userId);
            }
            if ($excludedUsers) {
                $query->whereNotIn('parser_tasks.user_id', $excludedUsers);
            }

            $task = $query->lockForUpdate()->first();
            if (! $task) {
                return null;
            }

            $credential = PortalCredential::query()
                ->where('user_id', $task->user_id)
                ->where('portal', $task->portal)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if (! $credential) {
                $task->forceFill(['status' => 'paused', 'next_run_at' => null])->save();
                continue;
            }

            $activeRuns = SyncRun::query()
                ->where('user_id', $task->user_id)
                ->where('status', 'running')
                ->where('lock_expires_at', '>', $now)
                ->count();
            if ($activeRuns >= $maxPerUser) {
                $excludedUsers[] = $task->user_id;
                continue;
            }

            $taskPayload = $this->taskPayload($task);
            if ($taskPayload === null) {
                $task->forceFill(['status' => 'completed', 'next_run_at' => null])->save();
                continue;
            }

            $task->forceFill([
                'status' => 'running',
                'locked_at' => $now,
                'lock_expires_at' => $now->copy()->addSeconds($lockSeconds),
                'locked_by' => $lockedBy,
                'attempts' => $task->attempts + 1,
                'last_started_at' => $now,
            ])->save();

            $syncRun = SyncRun::query()->create([
                'user_id' => $task->user_id,
                'parser_task_id' => $task->id,
                'roster_item_id' => $task->roster_item_id,
                'source' => $task->source,
                'task_type' => $task->task_type,
                'task_payload' => $taskPayload,
                'trigger' => 'scheduler',
                'status' => 'running',
                'started_at' => $now,
                'claimed_at' => $now,
                'heartbeat_at' => $now,
                'lock_expires_at' => $now->copy()->addSeconds($lockSeconds),
                'locked_by' => $lockedBy,
                'attempt' => $task->attempts,
            ]);

            return [$task, $credential, $syncRun, $taskPayload];
        }

        return null;
    }

    private function taskPayload(ParserTask $task): ?array
    {
        if ($task->task_type === 'roster_refresh') {
            $month = now()->utc()->startOfMonth();
            return [
                'months' => [
                    $month->format('Y-m'),
                    $month->copy()->addMonth()->format('Y-m'),
                ],
            ];
        }

        if ($task->task_type !== 'flight_details') {
            return null;
        }

        $item = $task->rosterItem()->first();
        if (! $item || ! $item->is_actual || $item->is_removed_from_source || ! $item->source_request_raw) {
            return null;
        }

        return [
            'roster_item_id' => $item->id,
            'source_external_id' => $item->source_external_id,
            'source_request_raw' => $item->source_request_raw,
            'boards_raw' => $item->boards_raw,
            'starts_at' => optional($item->starts_at)->utc()->toIso8601String(),
            'ends_at' => optional($item->ends_at)->utc()->toIso8601String(),
            'roster_updated_at' => optional($item->updated_at)->utc()->toIso8601String(),
        ];
    }

    private function releaseExpiredTasks(string $source, Carbon $now): void
    {
        $taskIds = ParserTask::query()
            ->where('source', $source)
            ->where('status', 'running')
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '<', $now)
            ->limit(50)
            ->pluck('id');

        foreach ($taskIds as $taskId) {
            $runs = SyncRun::query()
                ->where('parser_task_id', $taskId)
                ->where('status', 'running')
                ->lockForUpdate()
                ->get();
            $task = ParserTask::query()
                ->whereKey($taskId)
                ->where('status', 'running')
                ->where('lock_expires_at', '<', $now)
                ->lockForUpdate()
                ->first();
            if (! $task) {
                continue;
            }

            $runs
                ->each(function (SyncRun $run) use ($now) {
                    $run->forceFill([
                        'status' => 'failed',
                        'finished_at' => $now,
                        'duration_ms' => $run->started_at
                            ? $run->started_at->diffInMilliseconds($now)
                            : null,
                        'lock_expires_at' => null,
                        'locked_by' => null,
                        'error_text' => 'Parser worker lease expired.',
                    ])->save();
                });

            $task->forceFill([
                'status' => 'scheduled',
                'next_run_at' => $now,
                'locked_at' => null,
                'lock_expires_at' => null,
                'locked_by' => null,
                'last_finished_at' => $now,
                'last_error_at' => $now,
                'last_error_text' => 'Parser worker lease expired.',
            ])->save();
        }
    }
}
