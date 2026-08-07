<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\PartialSyncResultRequest;
use App\Models\SyncRun;
use App\Services\SyncResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PartialSyncResultController extends Controller
{
    public function store(
        PartialSyncResultRequest $request,
        SyncRun $syncRun,
        SyncResultService $service
    ): JsonResponse {
        $payload = $request->validated();

        if ((int) $payload['sync_run_id'] !== $syncRun->id) {
            throw ValidationException::withMessages([
                'sync_run_id' => ['The payload sync_run_id must match the URL.'],
            ]);
        }

        if ((int) $payload['user_id'] !== (int) $syncRun->user_id) {
            throw ValidationException::withMessages([
                'user_id' => ['The user_id does not belong to this sync run.'],
            ]);
        }

        if ($payload['source'] !== $syncRun->source) {
            throw ValidationException::withMessages([
                'source' => ['The source does not belong to this sync run.'],
            ]);
        }

        if ($syncRun->task_type === 'flight_details' && $syncRun->roster_item_id) {
            $expectedVersion = $syncRun->task_payload['roster_updated_at'] ?? null;
            $currentVersion = optional($syncRun->rosterItem)->updated_at;
            if ($expectedVersion && $currentVersion && ! $currentVersion->equalTo(Carbon::parse($expectedVersion))) {
                throw new ConflictHttpException('The roster item changed while flight details were being parsed.');
            }
        }

        Log::info('Partial sync result received', [
            'sync_run_id' => $syncRun->id,
            'user_id' => $syncRun->user_id,
            'chunk_kind' => $payload['chunk_kind'],
            'roster_items_count' => count($payload['roster_items']),
            'flight_segments_count' => count($payload['flight_segments']),
        ]);

        $result = $service->storePartial($syncRun, $payload);

        Log::info('Partial sync result stored', [
            'sync_run_id' => $syncRun->id,
            'chunk_kind' => $payload['chunk_kind'],
            'duplicate' => $result['duplicate'],
            'stats' => $result['stats'],
        ]);

        unset($result['duplicate']);

        return response()->json($result);
    }
}
