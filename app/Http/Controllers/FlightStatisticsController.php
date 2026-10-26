<?php
namespace App\Http\Controllers;
use App\Models\{FlightReport, PortalProfile};
use Illuminate\Http\Request;
class FlightStatisticsController extends Controller {
    private function row(FlightReport $report, ?string $personnel): array {
        $d = $report->summary;
        $crew = collect($d['crew'] ?? [])->first(fn ($c) => $personnel && (string) ($c['personnel_number'] ?? '') === $personnel);
        $counts = $crew && in_array($crew['role'] ?? '', ['PIC', 'CO']);
        return ['id' => $report->id, 'flight_number' => $report->flight_number, 'flight_date' => $report->flight_date->toDateString(),
            'month' => $report->flight_date->format('Y-m'), 'departure' => $d['departure'] ?? '', 'arrival' => $d['arrival'] ?? '',
            'aircraft_type' => $d['aircraft_type'] ?? '', 'board' => $d['board'] ?? '', 'counted' => (bool) $counts,
            'airborne_minutes' => $counts ? $d['airborne_minutes'] : 0, 'flight_minutes' => $counts ? $d['flight_minutes'] : 0,
            'night_minutes' => $counts ? ($d['night_minutes'] ?? 0) : 0,
            'takeoffs' => $personnel && ($d['takeoff_pf'] ?? null) === $personnel ? 1 : 0,
            'landings' => $personnel && ($d['landing_pf'] ?? null) === $personnel ? 1 : 0];
    }
    public function index(Request $request) {
        abort_unless($request->user()->hasStatisticsAccess(), 403, 'Статистика пока недоступна для этого аккаунта.');
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $personnel = PortalProfile::find($request->user()->id)?->personnel_number;
        $all = FlightReport::where('user_id', $request->user()->id)->orderByDesc('flight_date')->get(['id', 'flight_number', 'flight_date', 'summary'])
            ->map(fn ($r) => $this->row($r, $personnel ? (string) $personnel : null));
        $rows = $request->filled('month') ? $all->where('month', $request->input('month'))->values() : $all;
        $total = fn ($items) => ['flights' => $items->where('counted', true)->count(),
            'airborne_minutes' => $items->sum('airborne_minutes'), 'flight_minutes' => $items->sum('flight_minutes'),
            'night_minutes' => $items->sum('night_minutes'), 'takeoffs' => $items->sum('takeoffs'), 'landings' => $items->sum('landings')];
        $groups = [];
        foreach (['aircraft_type', 'board', 'departure', 'arrival'] as $key) {
            $groups[$key] = $rows->where('counted', true)->groupBy($key)->map(fn ($items, $name) => ['name' => $name ?: 'Не указан'] + $total($items))->values();
        }
        $groups['routes'] = $rows->where('counted', true)->groupBy(fn ($r) => $r['departure'].' → '.$r['arrival'])
            ->map(fn ($items, $name) => ['name' => $name] + $total($items))->values();
        return response()->json(['totals' => $total($rows), 'months' => $all->pluck('month')->unique()->values(),
            'monthly' => $all->groupBy('month')->map(fn ($items, $name) => ['name' => $name] + $total($items))->values(),
            'groups' => $groups, 'flights' => $rows, 'unmatched' => $rows->where('counted', false)->count()]);
    }
    public function download(Request $request, FlightReport $flightReport) {
        abort_unless($request->user()->hasStatisticsAccess(), 403, 'Статистика пока недоступна для этого аккаунта.');
        abort_unless($flightReport->user_id === $request->user()->id, 404);
        $disk = \Illuminate\Support\Facades\Storage::disk('flight_reports');
        abort_unless($flightReport->expires_at->isFuture() && $flightReport->storage_path && $disk->exists($flightReport->storage_path), 404);
        return $disk->download($flightReport->storage_path, 'flight-report-'.$flightReport->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private']);
    }
    public function show(Request $request, FlightReport $flightReport) {
        abort_unless($request->user()->hasStatisticsAccess(), 403, 'Статистика пока недоступна для этого аккаунта.');
        abort_unless($flightReport->user_id === $request->user()->id, 404);
        $available = $flightReport->expires_at->isFuture() && $flightReport->storage_path
            && \Illuminate\Support\Facades\Storage::disk('flight_reports')->exists($flightReport->storage_path);
        return response()->json(['data' => $flightReport->data, 'file_available' => (bool) $available]);
    }
}
