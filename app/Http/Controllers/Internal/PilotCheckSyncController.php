<?php
namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\{PilotCheck, SyncRun, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PilotCheckSyncController extends Controller
{
    public function store(Request $request, SyncRun $syncRun)
    {
        $data = $request->validate([
            'checks' => ['present', 'array', 'max:500'],
            'checks.*' => ['array:title,aircraft_type,document_number,issued_on,valid_from,due_on,portal_status'],
            'checks.*.title' => ['required', 'string', 'max:1000'],
            'checks.*.aircraft_type' => ['nullable', 'string', 'max:100'],
            'checks.*.document_number' => ['nullable', 'string', 'max:255'],
            'checks.*.issued_on' => ['nullable', 'date_format:Y-m-d'],
            'checks.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'checks.*.due_on' => ['nullable', 'date_format:Y-m-d'],
            'checks.*.portal_status' => ['nullable', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($syncRun, $data) {
            $run = SyncRun::lockForUpdate()->findOrFail($syncRun->id);
            abort_unless($run->status === 'running' && $run->task_type === 'checks_refresh'
                && $run->lock_expires_at?->isFuture(), 409, 'Sync run lease expired or wrong task.');
            $user = User::lockForUpdate()->findOrFail($run->user_id);
            abort_unless($user->hasReportsAccess(), 403, 'Extended subscription required.');
            $previous = DB::table('pilot_check_syncs')->where('user_id', $user->id)->first();
            if ($previous && $previous->sync_run_id >= $run->id) return;
            PilotCheck::where('user_id', $user->id)->where('origin', 'portal')->update(['is_current' => false]);
            foreach ($data['checks'] as $row) {
                $row = array_replace(array_fill_keys(['title', 'aircraft_type', 'document_number', 'issued_on', 'valid_from', 'due_on', 'portal_status'], null), $row);
                $identity = $row;
                unset($identity['portal_status']); // Human-readable countdown changes every day.
                $key = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE));
                PilotCheck::updateOrCreate(['user_id' => $user->id, 'source_key' => $key],
                    $row + ['origin' => 'portal', 'is_current' => true]);
            }
            DB::table('pilot_check_syncs')->updateOrInsert(['user_id' => $user->id], ['sync_run_id' => $run->id, 'synced_at' => now()]);
        });
        return response()->json(['ok' => true]);
    }
}
