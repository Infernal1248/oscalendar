<?php

namespace App\Console\Commands;

use App\Models\{ParserTask, PortalCredential, User};
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleFlightTime extends Command
{
    protected $signature = 'flight-time:schedule';
    protected $description = 'Seed one-time monthly history since 2020 and daily seven-day flight time tasks for all active portal users';

    public function handle(): int
    {
        $count = 0;
        PortalCredential::where('portal', 'rossiya_edu')->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))->chunkById(100, function ($credentials) use (&$count) {
                foreach ($credentials as $credential) {
                    $count += DB::transaction(function () use ($credential) {
                        User::whereKey($credential->user_id)->lockForUpdate()->firstOrFail();
                        $cutoff = now('UTC')->startOfDay()->subDays(7);
                        $task = ParserTask::firstOrCreate(['task_key' => "flight_time:rossiya_edu:user:{$credential->user_id}:rolling"], [
                            'user_id' => $credential->user_id, 'source' => 'rossiya_edu', 'portal' => 'rossiya_edu',
                            'task_type' => 'flight_time', 'status' => 'scheduled', 'priority' => 40, 'next_run_at' => now(),
                            'payload' => ['rolling_days' => 7, 'history_cutoff' => $cutoff->toDateString()],
                        ]);
                        // This marker is created in the same transaction as every history task.
                        // It freezes the initial cutoff; later scheduling never rewrites old months.
                        if (! $task->wasRecentlyCreated) return 0;
                        $rows = [];
                        for ($month = Carbon::parse('2020-01-01', 'UTC'); $month->lte($cutoff); $month->addMonth()) {
                            $rows[] = ['task_key' => "flight_time:rossiya_edu:user:{$credential->user_id}:".$month->format('Y-m'),
                                'user_id' => $credential->user_id, 'source' => 'rossiya_edu', 'portal' => 'rossiya_edu',
                                'task_type' => 'flight_time', 'status' => 'scheduled', 'priority' => 10, 'next_run_at' => now(),
                                'payload' => json_encode(['date_from' => $month->toDateString(),
                                    'date_to' => min($month->copy()->endOfMonth()->toDateString(), $cutoff->toDateString()), 'history' => true]),
                                'created_at' => now(), 'updated_at' => now()];
                        }
                        foreach (array_chunk($rows, 100) as $chunk) ParserTask::insertOrIgnore($chunk);
                        return count($rows) + 1;
                    }, 3);
                }
            });
        $this->info("Queued {$count} flight time tasks.");
        return self::SUCCESS;
    }
}
