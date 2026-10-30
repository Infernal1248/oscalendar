<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\{FlightTimeEntry, PortalProfile, SyncRun, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlightTimeSyncController extends Controller
{
    public function store(Request $request, SyncRun $syncRun)
    {
        $fields = ['entry_date', 'row_position', 'flight_number', 'description', 'flight_assignment_number', 'source_payload', ...FlightTimeEntry::MINUTES];
        $rules = [
            'date_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'nalet_user_id' => ['required', 'string', 'regex:/\A[1-9][0-9]{0,19}\z/'],
            'portal_identity' => ['required', 'array:full_name,personnel_number'],
            'portal_identity.full_name' => ['required', 'string', 'max:255'],
            'portal_identity.personnel_number' => ['required', 'string', 'regex:/\A[0-9]{1,64}\z/'],
            'entries' => ['present', 'array', 'max:5000'],
            'entries.*' => ['required', 'array:'.implode(',', $fields)],
            'entries.*.entry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from', 'before_or_equal:date_to'],
            'entries.*.row_position' => ['required', 'integer', 'min:1', 'max:5000'],
            'entries.*.flight_number' => ['present', 'nullable', 'string', 'max:100'],
            'entries.*.description' => ['required', 'string', 'max:1000'],
            'entries.*.flight_assignment_number' => ['present', 'nullable', 'string', 'max:100'],
            'entries.*.source_payload' => ['required', 'array', 'size:13'],
            'entries.*.source_payload.*' => ['present', 'nullable', 'string', 'max:1000'],
            'totals' => ['required', 'array:'.implode(',', FlightTimeEntry::MINUTES)],
        ];
        foreach (FlightTimeEntry::MINUTES as $field) {
            $rules["entries.*.{$field}"] = ['present', 'nullable', 'integer', 'min:0', 'max:10000000'];
            $rules["totals.{$field}"] = ['present', 'nullable', 'integer', 'min:0', 'max:10000000'];
        }
        $data = $request->validate($rules);
        $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE));
        DB::transaction(function () use ($syncRun, $data, $hash) {
            $run = SyncRun::lockForUpdate()->findOrFail($syncRun->id);
            abort_unless($run->status === 'running' && $run->task_type === 'flight_time' && $run->source === 'rossiya_edu'
                && $run->lock_expires_at?->isFuture(), 409, 'Flight time run lease expired or wrong task.');
            abort_unless(($run->task_payload['date_from'] ?? null) === $data['date_from']
                && ($run->task_payload['date_to'] ?? null) === $data['date_to'], 409, 'Flight time period does not match the job.');
            $user = User::whereKey($run->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === 'active', 403);
            if ($run->stats['flight_time_hash'] ?? null) {
                abort_unless(hash_equals($run->stats['flight_time_hash'], $hash), 409, 'Flight time snapshot already received.');
                return;
            }
            $profile = PortalProfile::find($run->user_id);
            abort_if($profile && $profile->personnel_number !== $data['portal_identity']['personnel_number'], 409, 'Flight time employee identity mismatch.');
            $positions = [];
            foreach ($data['entries'] as $entry) {
                $date = $entry['entry_date'];
                abort_unless($entry['row_position'] === ($positions[$date] ?? 0) + 1, 422, 'Invalid flight time row order.');
                $positions[$date] = $entry['row_position'];
            }
            foreach (FlightTimeEntry::MINUTES as $field) {
                abort_unless(array_sum(array_column($data['entries'], $field)) === (int) ($data['totals'][$field] ?? 0),
                    422, 'Flight time totals do not match the rows.');
            }
            FlightTimeEntry::where('user_id', $run->user_id)->whereBetween('entry_date', [$data['date_from'], $data['date_to']])->delete();
            foreach (array_chunk($data['entries'], 200) as $chunk) {
                FlightTimeEntry::insert(array_map(fn ($entry) => $entry + ['user_id' => $run->user_id, 'sync_run_id' => $run->id,
                    'created_at' => now(), 'updated_at' => now()], array_map(function ($entry) {
                        $entry['source_payload'] = json_encode(array_map(fn ($v) => $v ?? '', $entry['source_payload']), JSON_UNESCAPED_UNICODE);
                        return $entry;
                    }, $chunk)));
            }
            if (! $profile) PortalProfile::storeParsed($run->user_id, 'rossiya_edu', $data['portal_identity'], now()->toIso8601String());
            PortalProfile::whereKey($run->user_id)->update(['nalet_user_id' => $data['nalet_user_id']]);
            $run->forceFill(['stats' => array_merge($run->stats ?? [], ['flight_time_hash' => $hash,
                'flight_time_rows' => count($data['entries']), 'flight_time_totals' => $data['totals']])])->save();
        }, 3);
        return response()->json(['ok' => true]);
    }
}
