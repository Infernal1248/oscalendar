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
            'certificates' => ['sometimes', 'array', 'max:100'],
            'certificates.*' => ['array:title,aircraft_type,valid_from,due_on,portal_status,confirmation_path'],
            'certificates.*.title' => ['required', 'string', 'max:1000'],
            'certificates.*.aircraft_type' => ['nullable', 'string', 'max:100'],
            'certificates.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'certificates.*.due_on' => ['nullable', 'date_format:Y-m-d'],
            'certificates.*.portal_status' => ['nullable', 'string', 'max:500'],
            'certificates.*.confirmation_path' => ['required', 'in:/index/confirm_cr_educ-3'],
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
            abort_unless($run->status === 'running' && in_array($run->task_type, ['checks_refresh', 'checks_confirm_certificate'], true)
                && $run->lock_expires_at?->isFuture(), 409, 'Sync run lease expired or wrong task.');
            $user = User::lockForUpdate()->findOrFail($run->user_id);
            abort_unless($user->hasReportsAccess(), 403, 'Extended subscription required.');
            $previous = DB::table('pilot_check_syncs')->where('user_id', $user->id)->first();
            if ($previous && $previous->sync_run_id >= $run->id) return;
            PilotCheck::where('user_id', $user->id)->where('origin', 'portal')->update(['is_current' => false]);
            foreach ($data['checks'] as $row) {
                if (! PilotCheck::isSupportedPortalTitle($row['title'])) continue;
                $row = array_replace(array_fill_keys(['title', 'aircraft_type', 'document_number', 'issued_on', 'valid_from', 'due_on', 'portal_status'], null), $row);
                $identity = $row;
                unset($identity['portal_status']); // Human-readable countdown changes every day.
                $key = hash('sha256', json_encode($identity, JSON_UNESCAPED_UNICODE));
                PilotCheck::updateOrCreate(['user_id' => $user->id, 'source_key' => $key],
                    $row + ['origin' => 'portal', 'is_current' => true]);
            }
            $snapshot = ['sync_run_id'=>$run->id, 'synced_at'=>now()];
            if (array_key_exists('certificates', $data)) {
                $snapshot['certificates'] = json_encode($data['certificates'], JSON_UNESCAPED_UNICODE);
                $snapshot['certificates_synced_at'] = now();
            }
            DB::table('pilot_check_syncs')->updateOrInsert(['user_id'=>$user->id], $snapshot);
        });
        return response()->json(['ok' => true]);
    }
}
