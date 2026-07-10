<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\PortalCredential;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

    public function finish(Request $request, SyncRun $syncRun): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:32'],
            'error_text' => ['nullable', 'string'],
            'stats' => ['nullable', 'array'],
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

        $syncRun->forceFill([
            'status' => $data['status'] ?? 'finished',
            'finished_at' => isset($data['finished_at'])
                ? Carbon::parse($data['finished_at'])->utc()->toDateTimeString()
                : now(),
            'lock_expires_at' => null,
            'locked_by' => null,
            'error_text' => $data['error_text'] ?? null,
            'stats' => $stats,
            'items_found' => $stats['items_found'] ?? $syncRun->items_found,
            'items_created' => $stats['items_created'] ?? $syncRun->items_created,
            'items_updated' => $stats['items_updated'] ?? $syncRun->items_updated,
            'segments_found' => $stats['segments_found'] ?? $syncRun->segments_found,
            'segments_created' => $stats['segments_created'] ?? $syncRun->segments_created,
            'segments_updated' => $stats['segments_updated'] ?? $syncRun->segments_updated,
        ])->save();

        $this->updateCredentialStatus($syncRun);

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
