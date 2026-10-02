<?php

namespace App\Console\Commands;

use App\Models\FlightDocument;
use App\Models\FlightSegment;
use App\Services\FlightDocumentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneFlightDocuments extends Command
{
    protected $signature = 'flight-documents:prune';
    protected $description = 'Remove cached flight PDFs seven days after the end of their ring';

    public function handle(FlightDocumentService $service): int
    {
        $disk = Storage::disk('flight_documents');
        FlightDocument::whereNotNull('storage_path')->chunkById(100, function ($documents) use ($disk, $service) {
            foreach ($documents as $document) {
                DB::transaction(function () use ($document, $disk, $service) {
                    $segment = FlightSegment::lockForUpdate()->find($document->flight_segment_id);
                    $current = FlightDocument::find($document->id);
                    if (! $segment || ! $current?->storage_path) { return; }
                    $expiry = $service->expiry($segment);
                    $current->expires_at = $expiry;
                    if ($expiry && $expiry->lte(now())) {
                        if (! $disk->delete($current->storage_path)) {
                            throw new \RuntimeException('Could not remove expired flight document.');
                        }
                        $current->storage_path = null;
                    }
                    $current->save();
                });
            }
        });
        // Cascaded user/segment deletions and interrupted uploads can leave orphan files.
        foreach ($disk->allFiles() as $path) {
            if ($disk->lastModified($path) < now()->subDay()->timestamp
                && ! FlightDocument::where('storage_path', $path)->exists()) {
                $disk->delete($path);
            }
        }
        return self::SUCCESS;
    }
}
