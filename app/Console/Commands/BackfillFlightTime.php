<?php

namespace App\Console\Commands;

use App\Models\{ParserTask, PortalCredential, User};
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BackfillFlightTime extends Command
{
    protected $signature = 'flight-time:backfill {user_id} {from : First month, YYYY-MM} {to : Last month, YYYY-MM}';
    protected $description = 'Queue one-time monthly flight time history for a selected active portal user';

    public function handle(): int
    {
        $userId = (string) $this->argument('user_id');
        $from = (string) $this->argument('from');
        $to = (string) $this->argument('to');
        if (! preg_match('/\A[1-9][0-9]*\z/', $userId)
            || ! preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/', $from)
            || ! preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/', $to)
            || $from < '2018-01' || $to < $from || $to >= now('UTC')->format('Y-m')) {
            $this->error('Укажите ID пользователя и прошедшие месяцы в формате YYYY-MM, начиная с 2018-01.');
            return self::FAILURE;
        }
        $count = DB::transaction(function () use ($userId, $from, $to) {
            $user = User::whereKey($userId)->where('status', 'active')->lockForUpdate()->first();
            if (! $user || ! PortalCredential::where('user_id', $userId)->where('portal', 'rossiya_edu')->where('status', 'active')->exists()) {
                return null;
            }
            $count = 0;
            for ($month = Carbon::createFromFormat('!Y-m', $from, 'UTC'); $month->format('Y-m') <= $to; $month->addMonth()) {
                $task = ParserTask::firstOrCreate(['task_key' => "flight_time:rossiya_edu:user:{$userId}:".$month->format('Y-m')], [
                    'user_id' => $userId, 'source' => 'rossiya_edu', 'portal' => 'rossiya_edu',
                    'task_type' => 'flight_time', 'status' => 'scheduled', 'priority' => 10, 'next_run_at' => now(),
                    'payload' => ['date_from' => $month->toDateString(), 'date_to' => $month->copy()->endOfMonth()->toDateString(), 'history' => true],
                ]);
                $count += (int) $task->wasRecentlyCreated;
            }
            return $count;
        }, 3);
        if ($count === null) {
            $this->error('Не найден активный пользователь с активным подключением OpenSky.');
            return self::FAILURE;
        }
        $this->info("Пользователь #{$userId}: добавлено заданий — {$count}; период {$from} — {$to}. Существующие задания сохранены.");
        return self::SUCCESS;
    }
}
