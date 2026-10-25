<?php
namespace App\Console\Commands;
use App\Models\{ParserTask, RosterItem};
use Illuminate\Console\Command;
class ScheduleFlightReports extends Command {
    protected $signature = 'flight-reports:schedule';
    protected $description = 'Queue final reports for completed flights, including existing history';
    public function handle(): int {
        $count = 0;
        RosterItem::where('ends_at', '<', now())->where('is_actual', true)->where('is_removed_from_source', false)
            ->whereNotNull('source_request_raw')->whereHas('flightSegments')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->chunkById(200, function ($items) use (&$count) {
                foreach ($items as $item) {
                    $task = ParserTask::firstOrCreate(['task_key' => 'flight_report:'.$item->source.':roster:'.$item->id], [
                        'user_id' => $item->user_id, 'roster_item_id' => $item->id, 'source' => $item->source,
                        'portal' => $item->source, 'task_type' => 'flight_report', 'status' => 'scheduled',
                        'priority' => 10, 'next_run_at' => now(),
                    ]);
                    if ($task->wasRecentlyCreated) $count++;
                }
            });
        $this->info("Queued {$count} report tasks.");
        return self::SUCCESS;
    }
}
