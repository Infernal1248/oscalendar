<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\ParserJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $job = $service->claim($data);

        if (! $job) {
            return response()->json([
                'ok' => true,
                'job' => null,
            ]);
        }

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

        $syncRun = $service->heartbeat($syncRun, $data);

        return response()->json([
            'ok' => true,
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
            'lock_expires_at' => optional($syncRun->lock_expires_at)->toIso8601String(),
        ]);
    }
}
