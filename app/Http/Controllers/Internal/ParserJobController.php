<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\ParserJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ParserJobController extends Controller
{
    public function claim(Request $request, ParserJobService $service): JsonResponse
    {
        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:64'],
            'portal' => ['nullable', 'string', 'max:64'],
            'locked_by' => ['nullable', 'string', 'max:150'],
            'lock_seconds' => ['nullable', 'integer', 'min:60', 'max:7200'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        Log::info('Parser job claim requested', [
            'source' => $data['source'] ?? 'rossiya_edu',
            'portal' => $data['portal'] ?? ($data['source'] ?? 'rossiya_edu'),
            'locked_by' => $data['locked_by'] ?? null,
            'lock_seconds' => $data['lock_seconds'] ?? null,
            'user_id' => $data['user_id'] ?? null,
        ]);

        $job = $service->claim($data);

        if (! $job) {
            Log::info('Parser job claim returned no job', [
                'source' => $data['source'] ?? 'rossiya_edu',
                'portal' => $data['portal'] ?? ($data['source'] ?? 'rossiya_edu'),
                'user_id' => $data['user_id'] ?? null,
            ]);

            return response()->json([
                'ok' => true,
                'job' => null,
            ]);
        }

        Log::info('Parser job claimed', [
            'sync_run_id' => $job['sync_run_id'],
            'user_id' => $job['user_id'],
            'source' => $job['source'],
            'portal' => $job['portal'],
            'attempt' => $job['attempt'],
            'locked_by' => $job['locked_by'],
            'lock_expires_at' => $job['lock_expires_at'],
        ]);

        return response()->json([
            'ok' => true,
            'job' => $job,
        ]);
    }

    public function heartbeat(Request $request, SyncRun $syncRun, ParserJobService $service): JsonResponse
    {
        $data = $request->validate([
            'locked_by' => ['nullable', 'string', 'max:150'],
            'lock_seconds' => ['nullable', 'integer', 'min:60', 'max:7200'],
        ]);

        Log::info('Parser job heartbeat requested', [
            'sync_run_id' => $syncRun->id,
            'user_id' => $syncRun->user_id,
            'status' => $syncRun->status,
            'locked_by' => $data['locked_by'] ?? $syncRun->locked_by,
            'lock_seconds' => $data['lock_seconds'] ?? null,
        ]);

        $syncRun = $service->heartbeat($syncRun, $data);

        Log::info('Parser job heartbeat stored', [
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
            'locked_by' => $syncRun->locked_by,
            'lock_expires_at' => optional($syncRun->lock_expires_at)->toIso8601String(),
        ]);

        return response()->json([
            'ok' => true,
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
            'lock_expires_at' => optional($syncRun->lock_expires_at)->toIso8601String(),
        ]);
    }
}
