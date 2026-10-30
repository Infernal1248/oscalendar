<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\ParserJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['string', Rule::in(['typed_tasks_v1', 'roster_acknowledgement_v1', 'checks_v1', 'check_certificates_v1', 'flight_reports_v1'])],
        ]);

        $job = $service->claim($data);

        if (! $job) {
            return response()->json([
                'ok' => true,
                'job' => null,
            ]);
        }

        Log::info('Parser job claimed', [
            'sync_run_id' => $job['sync_run_id'],
            'task_id' => $job['task_id'],
            'task_type' => $job['task_type'],
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

        try {
            $syncRun = $service->heartbeat($syncRun, $data);
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
            Log::warning('Parser job heartbeat rejected', ['sync_run_id' => $syncRun->id]);
            throw $exception;
        }

        if (config('parser.log_heartbeats', false)) {
            Log::debug('Parser job heartbeat stored', [
                'sync_run_id' => $syncRun->id,
                'status' => $syncRun->status,
                'locked_by' => $syncRun->locked_by,
                'lock_expires_at' => optional($syncRun->lock_expires_at)->toIso8601String(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'sync_run_id' => $syncRun->id,
            'status' => $syncRun->status,
            'lock_expires_at' => optional($syncRun->lock_expires_at)->toIso8601String(),
        ]);
    }
}
