<?php
namespace App\Services;

use App\Models\{FlightDocument, FlightReport};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

class FlightFileStorage
{
    public function path(FlightDocument|FlightReport $record): string
    {
        $folder = $record->flight_segment_id ?: '0/'.$record->id;
        $name = $record instanceof FlightDocument ? $record->document_type.'.pdf' : 'report.xlsx';
        return $record->user_id.'/'.$folder.'/'.$name;
    }

    public function pruneOrphans(): void
    {
        $disk = Storage::disk('flight_files');
        foreach ($disk->allFiles() as $path) {
            // Never touch other private storage, including monitor metrics.
            if (! preg_match('~^\d+/(?:[1-9]\d*|0/\d+)/(?:ofp\.pdf|epz\.pdf|report\.xlsx)(?:\.[a-f0-9-]+\.tmp)?$~', $path)
                || $disk->lastModified($path) >= now()->subDay()->timestamp) continue;
            if (! FlightDocument::where('storage_disk', 'flight_files')->where('storage_path', $path)->exists()
                && ! FlightReport::where('storage_disk', 'flight_files')->where('storage_path', $path)->exists()) {
                $disk->delete($path);
            }
        }
    }

    // Caller holds the record/segment lock and a database transaction.
    public function write(FlightDocument|FlightReport $record, string $bytes): void
    {
        $disk = Storage::disk('flight_files');
        $path = $this->path($record);
        if ($record::where('storage_disk', 'flight_files')->where('storage_path', $path)
            ->when($record->exists, fn ($q) => $q->where('id', '!=', $record->id))->exists()) {
            throw new \RuntimeException('Flight file already belongs to another record.');
        }
        $oldDisk = $record->storage_disk ?? ($record instanceof FlightDocument ? 'flight_documents' : 'flight_reports');
        $old = $record->storage_path;
        $backup = $disk->exists($path) ? $disk->get($path) : null;
        $temporary = $path.'.'.Str::uuid().'.tmp';
        try {
            if (! $disk->put($temporary, $bytes)
                || hash('sha256', $disk->get($temporary)) !== hash('sha256', $bytes)
                || ! $disk->move($temporary, $path)) {
                throw new \RuntimeException('Could not store flight file.');
            }
            $record->storage_disk = 'flight_files';
            $record->storage_path = $path;
            $record->save();
        } catch (\Throwable $e) {
            $disk->delete($temporary);
            if ($backup !== null) $disk->put($path, $backup); else $disk->delete($path);
            throw $e;
        }
        if ($old && ($oldDisk !== 'flight_files' || $old !== $path)) {
            DB::afterCommit(fn () => Storage::disk($oldDisk)->delete($old));
        }
    }
}
