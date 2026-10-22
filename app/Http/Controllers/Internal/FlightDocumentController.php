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
            'file' => ['nullable', 'file', 'max:20480', 'prohibits:content_base64,failed'],
            'content_base64' => ['nullable', 'string', 'max:27962028', 'prohibits:file,failed'],
            'failed' => ['required_without_all:file,content_base64', 'in:1', 'prohibits:file,content_base64'],
        ]);
        $bytes = $request->file('file') ? file_get_contents($request->file('file')->getRealPath()) : null;
        if (isset($data['content_base64'])) {
            $encoded = $data['content_base64'];
            $length = strlen($encoded);
            $padding = $length - strlen(rtrim($encoded, '='));
            abort_if($length % 4 !== 0 || $padding > 2 || ($length / 4 * 3 - $padding) > 20 * 1024 * 1024,
                422, 'Invalid base64 document or document exceeds 20 MiB.');
            $bytes = base64_decode($encoded, true);
            abort_if($bytes === false || $bytes === '', 422, 'Invalid base64 document.');
        }
        abort_if($bytes !== null && strlen($bytes) > 20 * 1024 * 1024, 422, 'Document exceeds 20 MiB.');
        abort_if($bytes !== null && (! str_starts_with($bytes, '%PDF-') || ! str_contains($bytes, '%%EOF')), 422, 'Expected a complete PDF document.');
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
