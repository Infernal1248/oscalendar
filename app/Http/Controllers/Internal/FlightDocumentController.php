<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\FlightSegment;
use App\Models\SyncRun;
use App\Services\FlightDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FlightDocumentController extends Controller
{
    public function store(Request $request, SyncRun $syncRun, FlightDocumentService $service)
    {
        $data = $request->validate([
            'flight_number' => ['required', 'string'], 'starts_at' => ['required', 'date'],
            'document_type' => ['required', 'in:epz,ofp'], 'source_url' => ['required', 'url'],
            'file' => ['nullable', 'file', 'max:20480'],
            'failed' => ['required_without:file', 'in:1'],
        ]);
        $bytes = $request->file('file') ? file_get_contents($request->file('file')->getRealPath()) : null;
        abort_if($bytes !== null && (! str_starts_with($bytes, '%PDF-') || ! str_contains(substr($bytes, -2048), '%%EOF')), 422, 'Expected a complete PDF document.');
        DB::transaction(function () use ($syncRun, $data, $bytes, $service) {
            $run = SyncRun::lockForUpdate()->findOrFail($syncRun->id);
            abort_unless($run->status === 'running' && $run->task_type === 'flight_details'
                && $run->lock_expires_at?->isFuture(), 409, 'Sync run is no longer active.');
            $segment = FlightSegment::where('user_id', $run->user_id)->where('source', $run->source)
                ->where('roster_item_id', $run->roster_item_id)->where('flight_number', $data['flight_number'])
                ->where('starts_at', Carbon::parse($data['starts_at'])->utc())->lockForUpdate()->firstOrFail();
            $version = $run->task_payload['roster_updated_at'] ?? null;
            abort_if($version && ! $segment->rosterItem?->updated_at->equalTo(Carbon::parse($version)), 409);
            $expectedUrl = $data['document_type'] === 'epz'
                ? ($segment->download_doc_url ?: $segment->open_doc_url) : $segment->ofp_url;
            abort_unless($expectedUrl === $data['source_url'], 409, 'Document source changed.');
            $service->save($segment, $data['document_type'], $data['source_url'], $bytes);
        });
        return response()->json(['status' => 'accepted']);
    }
}
