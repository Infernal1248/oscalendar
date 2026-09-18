<?php

namespace App\Console\Commands;

use App\Models\{AirFase, GreenZone, RrjExpress, User};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateDemoReports extends Command
{
    protected $signature = 'demo:reports';
    protected $description = 'Добавить по 10 демонстрационных записей в отчёты для demo_premium';

    public function handle(): int
    {
        $user = User::where('login', 'demo_premium')->first();
        if (! $user || $user->portalProfile?->source !== 'demo' || $user->pilotRole() !== 'pilot' || $user->isAdmin()) {
            $this->error('Сначала создайте demo_premium командой demo:create. Ожидается демонстрационный профиль рядового пилота.');
            return self::FAILURE;
        }
        DB::transaction(function () use ($user) {
            foreach ([AirFase::class, RrjExpress::class, GreenZone::class] as $model) {
                for ($i = 1; $i <= 10; $i++) {
                    $row = [
                        'demo_user_id' => $user->id, 'flight_date' => now('UTC')->subDays($i)->toDateString(),
                        'flight_number' => (string) (9000 + $i), 'aircraft_registration' => 'DEMO-'.(100 + $i % 3),
                        'captain_name' => $user->portalProfile->full_name, 'captain_code' => $user->portalProfile->personnel_number,
                        'pilot_name' => $user->portalProfile->full_name, 'pilot_personnel_number' => $user->portalProfile->personnel_number,
                        'pilot_position' => $i % 2 ? 'КВС' : 'Второй пилот', 'flight_unit' => 'Демонстрационный отряд',
                        'fingerprint' => hash('sha256', "demo:{$user->id}:{$model}:{$i}"),
                        'source_filename' => 'demo-generated.xlsx', 'created_at' => now(), 'updated_at' => now(),
                    ];
                    if ($model === GreenZone::class) {
                        $row += ['aircraft_type' => 'RRJ-95B', 'flight_id' => 'DEMO-'.$i,
                            'takeoff_pitch' => 8 + $i / 10, 'max_pitch' => 14 + $i / 10, 'max_roll' => 20 + $i / 2,
                            'max_load' => 1.1 + $i / 100, 'landing_pitch' => 3 + $i / 10, 'landing_load' => 1.05 + $i / 100,
                            'threshold_distance' => 280 + $i * 15, 'threshold_time' => 4 + $i / 10];
                    } else {
                        $row += ['event_number' => (string) (1000 + $i % 3), 'report_event_count' => 10];
                        if ($model === AirFase::class) {
                            $row += ['event_text' => ['Demo: Speed during climb', 'Demo: Pitch at lift-off', 'Demo: Landing load'][$i % 3],
                                'level' => ['Low', 'Medium', 'High'][$i % 3], 'aircraft_type' => 'RRJ-95B',
                                'parameter' => ['SPEED', 'PITCH', 'LOAD'][$i % 3], 'parameter_value' => (string) (2 + $i / 10)];
                        } else {
                            $row += ['event_text' => ['Демо: скорость снижения', 'Демо: отклонение по тангажу', 'Демо: перегрузка на посадке'][$i % 3],
                                'duration' => '00:00:'.str_pad((string) ($i + 2), 2, '0', STR_PAD_LEFT)];
                        }
                    }
                    $table = DB::table((new $model)->getTable());
                    if (! $table->where('fingerprint', $row['fingerprint'])->exists()) {
                        DB::table((new $model)->getTable())->insert($row);
                    }
                }
            }
        });
        $this->info('Готово: по 10 записей для demo_premium. Повторный запуск не создаёт дубли и не меняет существующие строки.');
        return self::SUCCESS;
    }
}
