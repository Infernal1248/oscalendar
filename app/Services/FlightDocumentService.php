<?php

namespace App\Services;

use App\Models\FlightDocument;
use App\Models\FlightSegment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FlightDocumentService
{
    // A ring can span multiple days. Never expire a leg before the ring ends.
    public function expiry(FlightSegment $segment)
    {
        $end = $segment->rosterItem?->ends_at;
        $lastLeg = $segment->roster_item_id
            ? FlightSegment::where('roster_item_id', $segment->roster_item_id)->max('ends_at')
            : $segment->ends_at;
        if ($lastLeg && (! $end || $end->lt($lastLeg))) {
            $end = \Illuminate\Support\Carbon::parse($lastLeg);
        }
        return $end?->copy()->addDays(7);
    }

    public function available(FlightDocument $document, FlightSegment $segment): bool
    {
        $expires = $this->expiry($segment);
        return $expires && $expires->isFuture() && $document->storage_path
            && Storage::disk('flight_documents')->exists($document->storage_path);
    }

    public function metadata(FlightSegment $segment): array
    {
        $documents = FlightDocument::where('flight_segment_id', $segment->id)->get()->keyBy('document_type');
        return collect(['epz', 'ofp'])->map(function ($type) use ($segment, $documents) {
            $doc = $documents->get($type);
            return [
                'type' => $type,
                'source_view_url' => $type === 'epz' ? ($segment->open_doc_url ?: $segment->download_doc_url ?: $doc?->source_url) : ($segment->ofp_url ?: $doc?->source_url),
                'source_download_url' => $type === 'epz' ? ($segment->download_doc_url ?: $segment->open_doc_url ?: $doc?->source_url) : ($segment->ofp_url ?: $doc?->source_url),
                'available' => $doc ? $this->available($doc, $segment) : false,
                'last_checked_at' => $doc?->last_checked_at,
                'content_updated_at' => $doc?->content_updated_at,
                'expires_at' => $this->expiry($segment),
                'last_error_at' => $doc?->last_error_at,
            ];
        })->all();
    }

    // Caller holds a lock on the segment and owns the database transaction.
    public function save(FlightSegment $segment, string $type, string $url, ?string $bytes): void
    {
        $expires = $this->expiry($segment);
        if (! $expires || $expires->isPast()) {
            return;
        }
        $document = FlightDocument::firstOrNew(['flight_segment_id' => $segment->id, 'document_type' => $type]);
        $document->fill(['user_id' => $segment->user_id, 'source_url' => $url,
            'expires_at' => $expires, 'last_attempt_at' => now()]);
        if ($bytes === null) {
            $document->fill(['last_error_at' => now(), 'last_error' => 'Не удалось загрузить PDF из OpenSky.']);
            $document->save();
            return;
        }
        $disk = Storage::disk('flight_documents');
        $oldPath = $document->storage_path;
        $newPath = null;
        $hash = hash('sha256', $bytes);
        if ($hash !== $document->content_hash || ! $oldPath || ! $disk->exists($oldPath)) {
            $newPath = $segment->user_id.'/'.$segment->id.'/'.Str::uuid().'.pdf';
            if (! $disk->put($newPath, $bytes)) {
                throw new \RuntimeException('Could not store flight document.');
            }
            $document->storage_path = $newPath;
            if ($hash !== $document->content_hash) {
                $document->content_updated_at = now();
            }
        }
        $document->fill(['content_hash' => $hash, 'size' => strlen($bytes),
            'last_checked_at' => now(), 'last_error_at' => null, 'last_error' => null]);
        try {
            $document->save();
        } catch (\Throwable $e) {
            if ($newPath) { $disk->delete($newPath); }
            throw $e;
        }
        if ($newPath && $oldPath) {
            \Illuminate\Support\Facades\DB::afterCommit(fn () => $disk->delete($oldPath));
        }
    }
}
