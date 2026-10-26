<?php
namespace App\Console\Commands;
use App\Models\{ParserTask, RosterItem};
use Illuminate\Console\Command;
class ScheduleFlightReports extends Command {
    protected $signature = 'flight-reports:schedule {--retry : Requeue existing non-running report tasks}';
    protected $description = 'Queue final reports for completed flights, including existing history';
    public function handle(): int {
        $count = 0;
        $retried = 0;
        RosterItem::where(function ($query) {
                $query->where('ends_at', '<', now())
                    ->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '<', now()));
            })->where('is_actual', true)->where('is_removed_from_source', false)
            ->whereNotNull('source_request_raw')->where('source_request_raw', '!=', '')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->chunkById(200, function ($items) use (&$count, &$retried) {
                foreach ($items as $item) {
                    $task = ParserTask::firstOrCreate(['task_key' => 'flight_report:'.$item->source.':roster:'.$item->id], [
                        'user_id' => $item->user_id, 'roster_item_id' => $item->id, 'source' => $item->source,
                        'portal' => $item->source, 'task_type' => 'flight_report', 'status' => 'scheduled',
                        'priority' => 10, 'next_run_at' => now(),
                    ]);
                    if ($task->wasRecentlyCreated) {
                        $count++;
                    } elseif ($this->option('retry')) {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($task, &$retried) {
                            $task = ParserTask::lockForUpdate()->findOrFail($task->id);
                            if ($task->status === 'running') return;
                            $task->update([
                                'status' => 'scheduled', 'next_run_at' => now(),
                                'payload' => array_merge($task->payload ?? [], [
                                    'report_retry_until' => now()->addDays(7)->toIso8601String(),
                                ]),
                            ]);
                            $retried++;
                        });
                    }
                }
            });
        $this->info("Queued {$count} new report tasks; requeued {$retried} existing tasks.");
        return self::SUCCESS;
    }
}
