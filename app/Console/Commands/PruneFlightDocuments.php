<?php

namespace App\Console\Commands;

use App\Models\FlightSegment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneFlightDocuments extends Command
{
    protected $signature = 'flight-documents:prune {--months=12}';

    protected $description = 'Delete stored OFP documents for old flight segments';

    public function handle(): int
    {
        $before = now()->subMonths(max(1, (int) $this->option('months')));
        $deleted = 0;

        FlightSegment::query()
            ->where('starts_at', '<', $before)
            ->whereNotNull('ofp_pdf_path')
            ->chunkById(100, function ($segments) use (&$deleted) {
                foreach ($segments as $segment) {
                    Storage::disk('local')->delete($segment->ofp_pdf_path);
                    $segment->forceFill(['ofp_pdf_path' => null])->save();
                    $deleted++;
                }
            });

        $this->info("Deleted {$deleted} OFP document(s).");

        return self::SUCCESS;
    }
}
