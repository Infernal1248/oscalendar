<?php
namespace App\Http\Controllers\Internal;
use App\Http\Controllers\Controller;
use App\Models\{FlightReport, FlightSegment, RosterItem, SyncRun};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
class FlightReportController extends Controller {
    public function store(Request $request, SyncRun $syncRun) {
        $data = $request->validate([
            'flight_number' => 'required|string|max:100', 'starts_at' => 'required|date',
            'source_url' => 'required|url|max:4000', 'content_base64' => 'required|string|max:27962028',
            'report.sheets' => 'required|array|max:20',
            'report.navigation' => 'nullable|array|max:10000', 'report.history' => 'nullable|array|max:10000',
            'report.times' => 'nullable|array', 'report.fuel' => 'nullable|array',
            'report.aircraft_type' => 'nullable|string|max:100', 'report.board' => 'nullable|string|max:100',
            'report.departure' => 'nullable|string|max:500', 'report.arrival' => 'nullable|string|max:500',
            'report.notes' => 'nullable|string|max:100000', 'report.duty_duration' => 'nullable',
            'report.crew.*.name' => 'nullable|string|max:500',
            'report' => 'required|array', 'report.schema_version' => 'required|in:1',
            'report.flight_number' => 'required|string|max:100', 'report.flight_date' => 'required|date_format:Y-m-d',
            'report.airborne_minutes' => 'required|integer|min:0|max:10080',
            'report.flight_minutes' => 'required|integer|min:0|max:10080',
            'report.night_minutes' => 'nullable|integer|min:0|max:10080',
            'report.flight_night_minutes' => 'nullable|integer|min:0|max:10080',
            'report.crew' => 'required|array|max:100', 'report.crew.*.personnel_number' => 'required|string|max:100',
            'report.crew.*.role' => 'required|string|max:100',
            'report.takeoff_pf' => 'nullable|string|max:100', 'report.landing_pf' => 'nullable|string|max:100',
        ]);
        $bytes = base64_decode($data['content_base64'], true);
        abort_unless($bytes && strlen($bytes) <= 20*1024*1024 && str_starts_with($bytes, "PK\x03\x04"), 422, 'Expected XLSX.');
        $temporary = tempnam(sys_get_temp_dir(), 'flight-report-');
        $zip = new \ZipArchive();
        try {
            file_put_contents($temporary, $bytes);
            abort_unless($zip->open($temporary) === true, 422, 'Invalid XLSX archive.');
            abort_unless($zip->locateName('xl/workbook.xml') !== false && $zip->locateName('[Content_Types].xml') !== false, 422, 'Invalid XLSX workbook.');
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) $expanded += $zip->statIndex($i)['size'];
            abort_if($expanded > 50 * 1024 * 1024, 422, 'Expanded workbook exceeds limit.');
        } finally {
            if ($zip->numFiles) $zip->close();
            @unlink($temporary);
        }
        abort_unless($data['report']['flight_number'] === $data['flight_number']
            && $data['report']['flight_date'] === Carbon::parse($data['starts_at'])->utc()->toDateString(), 422, 'Report identity mismatch.');
        DB::transaction(function () use ($syncRun, $data, $bytes) {
            $run = SyncRun::lockForUpdate()->findOrFail($syncRun->id);
            abort_unless($run->status === 'running' && $run->task_type === 'flight_report' && $run->lock_expires_at?->isFuture(), 409);
            $item = RosterItem::where('user_id', $run->user_id)->where('source', $run->source)
                ->lockForUpdate()->findOrFail($run->roster_item_id);
            abort_unless($item->is_actual && ! $item->is_removed_from_source
                && ($item->ends_at ?? $item->starts_at)?->isPast(), 409);
            $startsAt = Carbon::parse($data['starts_at'])->utc();
            abort_unless($item->starts_at && $startsAt->betweenIncluded(
                $item->starts_at->copy()->startOfDay(), $item->ends_at?->copy()->endOfDay() ?? now()) && $startsAt->isPast(), 422, 'Flight outside roster dates.');
            $segment = FlightSegment::where('user_id', $run->user_id)->where('source', $run->source)
                ->where('roster_item_id', $run->roster_item_id)->where('flight_number', $data['flight_number'])
                ->where('starts_at', Carbon::parse($data['starts_at'])->utc())->lockForUpdate()->first();
            if ($segment) abort_unless($segment->ends_at && $segment->ends_at->isPast(), 409);
            // Use the portal leg identity, independent of file contents or report revisions.
            $url = preg_replace_callback('/[^\x21-\x7E]/u', fn ($m) => rawurlencode($m[0]), $data['source_url']);
            $query = [];
            foreach (explode('&', parse_url($url, PHP_URL_QUERY) ?? '') as $part) {
                [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
                $query[rawurldecode($name)] = rawurldecode($value);
            }
            abort_unless(str_contains(parse_url($url, PHP_URL_PATH) ?? '', '/workplan/view_flight_report-1')
                && ($query['flight_number'] ?? null) === $data['flight_number']
                && ($query['id_para'] ?? null) == ($segment?->source_para_id ?? $item->source_external_id), 422, 'Invalid report link.');
            $key = hash('sha256', implode('|', [$run->source, $data['flight_number'],
                $data['report']['flight_date'], $query['legnum'] ?? '', $query['departure_airport_id'] ?? '', $query['arrival_airport_id'] ?? '']));
            $report = FlightReport::lockForUpdate()->firstOrNew(['user_id' => $run->user_id, 'source_key' => $key]);
            if ($report->exists && $report->sync_run_id > $run->id) return;
            $hash = hash('sha256', $bytes);
            $disk = Storage::disk('flight_reports');
            $old = $report->storage_path;
            $changed = $report->content_hash !== $hash;
            $path = $old;
            if ($changed || (! $old && ! $report->exists) || ($old && ! $disk->exists($old))) {
                $path = $run->user_id.'/'.Str::uuid().'.xlsx';
                if (! $disk->put($path, $bytes)) throw new \RuntimeException('Could not store report.');
            }
            $report->fill(['flight_segment_id' => $segment?->id, 'flight_number' => $data['flight_number'],
                'flight_date' => $data['report']['flight_date'], 'data' => $data['report'], 'summary' => collect($data['report'])->except(['sheets', 'navigation', 'history'])->all(), 'content_hash' => $hash,
                'source_url' => $data['source_url'], 'storage_path' => $path, 'sync_run_id' => $run->id, 'last_checked_at' => now()]);
            if (! $report->exists || $changed) $report->expires_at = now()->addDays(60);
            try { $report->save(); } catch (\Throwable $e) { if ($path !== $old) $disk->delete($path); throw $e; }
            if ($old && $path !== $old) DB::afterCommit(fn () => $disk->delete($old));
        });
        return response()->json(['ok' => true]);
    }
}
