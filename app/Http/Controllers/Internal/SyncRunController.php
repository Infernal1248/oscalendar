<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\PortalCredential;
use App\Models\SyncRun;
use App\Services\ParserTaskScheduler;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SyncRunController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'source' => ['nullable', 'string', 'max:64'],
            'trigger' => ['nullable', 'string', 'max:32'],
            'started_at' => ['nullable', 'date'],
        ]);

        Log::info('Sync run start requested', [
            'user_id' => $data['user_id'] ?? null,
            'source' => $data['source'] ?? 'rossiya_edu',
            'trigger' => $data['trigger'] ?? 'scheduler',
        ]);

        $syncRun = SyncRun::query()->create([
            'user_id' => $data['user_id'] ?? null,
            'source' => $data['source'] ?? 'rossiya_edu',
            'trigger' => $data['trigger'] ?? 'scheduler',
            'status' => 'running',
            'started_at' => isset($data['started_at'])
                ? Carbon::parse($data['started_at'])->utc()->toDateTimeString()
                : now(),
        ]);

        return response()->json([
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
        ], 201);
    }

    public function finish(Request $request, SyncRun $syncRun, ParserTaskScheduler $taskScheduler): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['finished', 'failed'])],
            'error_text' => ['nullable', 'required_if:status,failed', 'string'],
            'stats' => ['nullable', 'array'],
            'stats.items_found' => ['nullable', 'integer', 'min:0'],
            'stats.items_created' => ['nullable', 'integer', 'min:0'],
            'stats.items_updated' => ['nullable', 'integer', 'min:0'],
            'stats.segments_found' => ['nullable', 'integer', 'min:0'],
            'stats.segments_created' => ['nullable', 'integer', 'min:0'],
            'stats.segments_updated' => ['nullable', 'integer', 'min:0'],
            'finished_at' => ['nullable', 'date'],
        ]);

        $stats = $data['stats'] ?? [];

        Log::info('Sync run finish requested', [
            'sync_run_id' => $syncRun->id,
            'user_id' => $syncRun->user_id,
            'current_status' => $syncRun->status,
            'new_status' => $data['status'] ?? 'finished',
            'has_error_text' => ! empty($data['error_text']),
            'stats' => $stats,
        ]);

        $syncRun = DB::transaction(function () use ($syncRun, $data, $stats, $taskScheduler) {
            $syncRun = SyncRun::query()->lockForUpdate()->findOrFail($syncRun->id);

            if (in_array($syncRun->status, ['finished', 'failed'], true)) {
                if ($syncRun->status !== $data['status']) {
                    throw new ConflictHttpException('The sync run is already closed with another status.');
                }

                return $syncRun;
            }

            if (! in_array($syncRun->status, ['queued', 'running'], true)) {
                throw new ConflictHttpException('The sync run cannot be finished from its current status.');
            }

            $finishedAt = isset($data['finished_at'])
                ? Carbon::parse($data['finished_at'])->utc()
                : now();
            $syncRun->forceFill([
                'status' => $data['status'],
                'finished_at' => $finishedAt,
                'duration_ms' => $syncRun->started_at
                    ? $syncRun->started_at->diffInMilliseconds($finishedAt)
                    : null,
                'lock_expires_at' => null,
                'locked_by' => null,
                'error_text' => $data['status'] === 'failed' ? ($data['error_text'] ?? null) : null,
                'stats' => array_merge($syncRun->stats ?? [], $stats),
                'items_found' => $stats['items_found'] ?? $syncRun->items_found,
                'items_created' => $stats['items_created'] ?? $syncRun->items_created,
                'items_updated' => $stats['items_updated'] ?? $syncRun->items_updated,
                'segments_found' => $stats['segments_found'] ?? $syncRun->segments_found,
                'segments_created' => $stats['segments_created'] ?? $syncRun->segments_created,
                'segments_updated' => $stats['segments_updated'] ?? $syncRun->segments_updated,
            ])->save();

            $this->updateCredentialStatus($syncRun);
            $taskScheduler->completeTask($syncRun);

            return $syncRun;
        });

        Log::info('Sync run finished', [
            'sync_run_id' => $syncRun->id,
            'user_id' => $syncRun->user_id,
            'status' => $syncRun->status,
            'finished_at' => optional($syncRun->finished_at)->toIso8601String(),
        ]);

        return response()->json([
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
        ]);
    }

    private function updateCredentialStatus(SyncRun $syncRun): void
    {
        if (! $syncRun->user_id) {
            return;
        }

        $attributes = $syncRun->status === 'finished'
            ? [
                'last_success_at' => $syncRun->finished_at ?: now(),
                'last_error_at' => null,
                'last_error_text' => null,
            ]
            : [
                'last_error_at' => $syncRun->finished_at ?: now(),
                'last_error_text' => $syncRun->error_text,
            ];

        PortalCredential::query()
            ->where('user_id', $syncRun->user_id)
            ->where('portal', $syncRun->source)
            ->update($attributes);
    }

    public function log(Request $request, SyncRun $syncRun): JsonResponse
    {
        $data = $request->validate([
            'level' => ['nullable', 'string', 'max:16'],
            'message' => ['required', 'string'],
            'context' => ['nullable', 'array'],
        ]);

        Log::info('Sync run log requested', [
            'sync_run_id' => $syncRun->id,
            'user_id' => $syncRun->user_id,
            'level' => $data['level'] ?? 'info',
            'message' => $data['message'],
            'context_keys' => array_keys($data['context'] ?? []),
        ]);

        $log = $syncRun->logs()->create([
            'level' => $data['level'] ?? 'info',
            'message' => $data['message'],
            'context' => $this->redactContext($data['context'] ?? []),
        ]);

        return response()->json([
            'sync_log_id' => $log->id,
        ], 201);
    }

    private function redactContext(array $context): array
    {
        foreach ($context as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, ['password', 'password_encrypted', 'token', 'authorization', 'phones'], true)) {
                $context[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->redactContext($value);
            }
        }

        return $context;
    }
}
