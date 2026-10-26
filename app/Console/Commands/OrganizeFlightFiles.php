<?php
namespace App\Console\Commands;

use App\Models\{FlightDocument, FlightReport, FlightSegment};
use App\Services\FlightFileStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Storage};

class OrganizeFlightFiles extends Command
{
    protected $signature = 'flight-files:organize';
    protected $description = 'Move existing PDFs and XLSX into private/{user}/{flight} without changing retention';

    public function handle(FlightFileStorage $files): int
    {
        $moved = 0;
        $missing = 0;
        foreach ([FlightDocument::class, FlightReport::class] as $model) {
            $model::whereNotNull('storage_path')->chunkById(100, function ($rows) use ($files, &$moved, &$missing, $model) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $files, &$moved, &$missing, $model) {
                        // Same lock order as PDF uploads and pruning.
                        if ($row instanceof FlightDocument) FlightSegment::lockForUpdate()->find($row->flight_segment_id);
                        $current = $model::lockForUpdate()->find($row->id);
                        if (! $current?->storage_path) return;
                        $oldDisk = $current->storage_disk;
                        $old = $current->storage_path;
                        if ($oldDisk === 'flight_files' && $old === $files->path($current)) return;
                        $disk = Storage::disk($oldDisk);
                        if (! $disk->exists($old)) {
                            $this->warn(class_basename($model).' #'.$current->id.': source file missing; unchanged.');
                            $missing++;
                            return;
                        }
                        $bytes = $disk->get($old);
                        if ($current->content_hash && hash('sha256', $bytes) !== $current->content_hash) {
                            throw new \RuntimeException(class_basename($model).' #'.$current->id.': content hash mismatch; unchanged.');
                        }
                        $destination = Storage::disk('flight_files');
                        $path = $files->path($current);
                        if ($destination->exists($path) && hash('sha256', $destination->get($path)) !== hash('sha256', $bytes)) {
                            throw new \RuntimeException('Destination conflict: '.$path.'; unchanged.');
                        }
                        $files->write($current, $bytes);
                        $moved++;
                    });
                }
            });
        }
        $this->info("Moved {$moved} files; missing {$missing}. Existing retention dates preserved.");
        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
