<?php
namespace App\Console\Commands;
use App\Models\FlightReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Storage};
class PruneFlightReports extends Command {
    protected $signature = 'flight-reports:prune';
    protected $description = 'Delete expired XLSX files while retaining parsed statistics';
    public function handle(): int {
        $disk = Storage::disk('flight_reports');
        FlightReport::whereNotNull('storage_path')->where('expires_at', '<=', now())->chunkById(100, function ($rows) use ($disk) {
            foreach ($rows as $row) DB::transaction(function () use ($row, $disk) {
                $current = FlightReport::lockForUpdate()->find($row->id);
                if (! $current?->storage_path || $current->expires_at->isFuture()) return;
                if (! $disk->delete($current->storage_path)) throw new \RuntimeException('Could not prune report.');
                $current->update(['storage_path' => null]);
            });
        });
        foreach ($disk->allFiles() as $path) {
            if ($disk->lastModified($path) < now()->subDay()->timestamp && ! FlightReport::where('storage_path', $path)->exists()) $disk->delete($path);
        }
        return self::SUCCESS;
    }
}
