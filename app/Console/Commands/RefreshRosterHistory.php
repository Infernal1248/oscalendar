<?php

namespace App\Console\Commands;

use App\Models\{ParserTask, PortalCredential};
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RefreshRosterHistory extends Command
{
    protected $signature = 'roster:refresh-history {--from= : First month, YYYY-MM} {--to= : Last month, YYYY-MM}';
    protected $description = 'Re-fetch historical roster months for all active portal accounts';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        foreach ([$from, $to] as $month) {
            if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                $this->error('Provide --from and --to in YYYY-MM format.');
                return self::FAILURE;
            }
        }
        if ($from > $to || $to > now()->format('Y-m')) {
            $this->error('Use an ordered range ending no later than the current month.');
            return self::FAILURE;
        }
        $months = [];
        for ($date = Carbon::parse($from.'-01'); $date->format('Y-m') <= $to; $date->addMonth()) {
            $months[] = $date->format('Y-m');
        }
        $queued = 0;
        $running = 0;
        PortalCredential::where('portal', 'rossiya_edu')->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->chunkById(100, function ($credentials) use ($months, &$queued, &$running) {
                foreach ($credentials as $credential) {
                    $base = ['user_id'=>$credential->user_id, 'source'=>'rossiya_edu', 'portal'=>'rossiya_edu',
                        'task_type'=>'roster_refresh', 'status'=>'scheduled', 'priority'=>70, 'next_run_at'=>now()];
                    // Preserve the normal recurring task separately from one-off history jobs.
                    ParserTask::firstOrCreate(['task_key'=>"roster_refresh:rossiya_edu:user:{$credential->user_id}"], $base);
                    foreach ($months as $month) {
                        DB::transaction(function () use ($credential, $base, $month, &$queued, &$running) {
                            $task = ParserTask::firstOrCreate([
                                'task_key'=>"roster_history:rossiya_edu:user:{$credential->user_id}:{$month}",
                            ], array_merge($base, ['priority'=>20, 'payload'=>['history'=>true, 'months'=>[$month]]]));
                            $task = ParserTask::lockForUpdate()->findOrFail($task->id);
                            if ($task->status === 'running') { $running++; return; }
                            $task->update(['status'=>'scheduled', 'next_run_at'=>now()]);
                            $queued++;
                        });
                    }
                }
            });
        $this->info("Queued {$queued} historical month tasks; {$running} already running.");
        return self::SUCCESS;
    }
}
