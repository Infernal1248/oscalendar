<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $syncRun->forceFill([
            'status' => $data['status'] ?? 'finished',
            'finished_at' => isset($data['finished_at'])
                ? Carbon::parse($data['finished_at'])->utc()->toDateTimeString()
                : now(),
            'error_text' => $data['error_text'] ?? null,
            'stats' => $stats,
            'items_found' => $stats['items_found'] ?? $syncRun->items_found,
            'items_created' => $stats['items_created'] ?? $syncRun->items_created,
            'items_updated' => $stats['items_updated'] ?? $syncRun->items_updated,
            'segments_found' => $stats['segments_found'] ?? $syncRun->segments_found,
            'segments_created' => $stats['segments_created'] ?? $syncRun->segments_created,
            'segments_updated' => $stats['segments_updated'] ?? $syncRun->segments_updated,
        ])->save();

        return response()->json([
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
        ]);
    }

    public function log(Request $request, SyncRun $syncRun): JsonResponse
    {
        $data = $request->validate([
            'level' => ['nullable', 'string', 'max:16'],
            'message' => ['required', 'string'],
            'context' => ['nullable', 'array'],
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
