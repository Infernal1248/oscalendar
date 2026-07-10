<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SyncResultRequest;
use App\Services\SyncResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SyncResultController extends Controller
{
    public function store(SyncResultRequest $request, SyncResultService $service): JsonResponse
    {
        $payload = $request->validated();

        Log::info('Sync result received', [
            'sync_run_id' => $payload['sync_run_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'source' => $payload['source'] ?? null,
            'trigger' => $payload['trigger'] ?? null,
            'roster_items_count' => count($payload['roster_items'] ?? []),
            'flight_segments_count' => count($payload['flight_segments'] ?? []),
        ]);

        $result = $service->store($payload);

        Log::info('Sync result stored', [
            'sync_run_id' => $result['sync_run_id'] ?? null,
            'status' => $result['status'] ?? null,
            'stats' => $result['stats'] ?? [],
        ]);

        return response()->json($result);
    }
}
