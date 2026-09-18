<?php

namespace App\Console\Commands;

use App\Models\{AirFase, GreenZone, PortalProfile, Role, RosterChangeEvent, RosterItem, RrjExpress, SubscriptionPayment, User};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Hash};
use Illuminate\Support\Str;

class CreateDemoUsers extends Command
{
    protected $signature = 'demo:create';
    protected $description = 'Создать два демонстрационных аккаунта с вымышленным рабочим планом';

    public function handle(): int
    {
        if (User::whereIn('login', ['demo_basic', 'demo_premium'])->exists()) {
            $this->error('Демоаккаунты уже существуют. Ничего не изменено.');
            return self::FAILURE;
        }

        $credentials = DB::transaction(function () {
            $permissions = ['dashboard.view', 'profile.view', 'workplan.view', 'history.view',
                'airfase.view', 'airfase.read', 'green-zone.view', 'green-zone.read', 'rrj-express.view', 'rrj-express.read'];
            // A separate read-only role avoids inheriting locally customized default-user permissions.
            $role = Role::firstOrCreate(['key' => 'demo-viewer'], [
                'name' => 'Демонстрационный просмотр', 'permissions' => $permissions,
                'description' => 'Личный кабинет и просмотр отчётов без управления и импорта.',
            ]);
            if (array_diff($role->permissions, $permissions) || array_diff($permissions, $role->permissions)) {
                throw new \RuntimeException('Права роли demo-viewer изменены. Создание остановлено.');
            }
            $pilot = Role::where('key', 'pilot')->sole();
            $today = now('UTC')->startOfDay();
            $credentials = [];

            foreach (['basic' => 'Демо Базовый', 'premium' => 'Демо Премиум'] as $plan => $displayName) {
                do {
                    $number = '990'.random_int(100000000, 999999999);
                    $used = PortalProfile::where('personnel_number', $number)->exists();
                    foreach ([AirFase::class, GreenZone::class, RrjExpress::class] as $model) {
                        $used = $used || $model::where('pilot_personnel_number', $number)->orWhere('captain_code', $number)->exists();
                    }
                } while ($used);
                $password = Str::random(20);
                $user = User::create([
                    'login' => 'demo_'.$plan, 'password' => Hash::make($password),
                    'display_name' => $displayName, 'timezone' => 'Europe/Moscow', 'status' => 'active', 'role' => 'user',
                ]);
                $user->roles()->sync([$role->id, $pilot->id]);
                PortalProfile::create([
                    'user_id' => $user->id, 'source' => 'demo', 'full_name' => $displayName.' Вымышленный',
                    'personnel_number' => $number, 'synced_at' => now(),
                ]);

                foreach ([
                    [1, 'ОФИС', 6, 8, 'Учебный офис'],
                    [3, 'Тренажёр RRJ', 7, 4, 'Учебный центр'],
                    [5, 'ДЕМО9001/ДЕМО9002', 5, 7, 'Москва / Казань / Москва'],
                    [8, 'ДОТ', 8, 3, 'Удалённо'],
                    [10, 'ДЕМО9003/ДЕМО9004', 9, 6, 'Москва / Самара / Москва'],
                    [13, 'Подготовка к полётам', 6, 4, 'Учебный центр'],
                    [16, 'Плановый отпуск', 0, 168, ''],
                ] as $index => [$offset, $title, $hour, $hours, $route]) {
                    $start = $today->copy()->addDays($offset)->addHours($hour);
                    $item = RosterItem::create([
                        'user_id' => $user->id, 'source' => 'demo', 'source_external_id' => 'demo-'.$index,
                        'kind' => str_starts_with($title, 'ДЕМО') ? 'flight_ring' : 'other', 'title' => $title,
                        'flight_numbers_raw' => $title, 'route_raw' => $route,
                        'aircraft_type_raw' => str_starts_with($title, 'ДЕМО') ? 'RRJ-95B' : null,
                        'starts_at' => $start, 'ends_at' => $start->copy()->addHours($hours),
                        'is_actual' => true, 'is_removed_from_source' => false,
                        'source_payload' => ['all_day' => $title === 'Плановый отпуск'],
                    ]);
                    if ($index > 2) continue;
                    $after = $item->only(['kind', 'title', 'flight_numbers_raw', 'aircraft_type_raw', 'boards_raw', 'route_raw']);
                    $after['starts_at'] = $start->toIso8601String();
                    $after['ends_at'] = $item->ends_at->toIso8601String();
                    $before = array_replace($after, ['starts_at' => $start->copy()->subHour()->toIso8601String()]);
                    $detected = $today->copy()->subDays(3 - $index)->addHours(9);
                    RosterChangeEvent::create([
                        'user_id' => $user->id, 'source' => 'demo', 'period' => $start->format('Y-m'),
                        'change_hash' => hash('sha256', 'demo-'.$index), 'status' => $index === 2 ? 'superseded' : 'acknowledged',
                        'changes' => [['change_type' => 'changed', 'before' => $before, 'after' => $after,
                            'changed_fields' => ['starts_at' => ['label' => 'Дата и время', 'before' => $before['starts_at'], 'after' => $after['starts_at']]]]],
                        'notified_at' => $detected, 'acknowledgement_requested_at' => $index === 2 ? null : $detected->copy()->addMinutes(5),
                        'acknowledged_at' => $index === 2 ? null : $detected->copy()->addMinutes(6),
                        'acknowledgement_notified_at' => $index === 2 ? null : $detected->copy()->addMinutes(6),
                        'superseded_at' => $index === 2 ? $detected->copy()->addHour() : null,
                    ])->forceFill(['created_at' => $detected, 'updated_at' => $detected->copy()->addHour()])->save();
                }

                if ($plan === 'premium') {
                    SubscriptionPayment::create([
                        'user_id' => $user->id, 'request_id' => (string) Str::uuid(), 'source' => 'demo',
                        'amount_kopecks' => 0, 'duration_days' => 365, 'paid_at' => $today,
                        'starts_at' => $today, 'ends_at' => $today->copy()->addDays(365)->endOfDay(),
                        'recorded_by' => $user->id, 'comment' => 'Демонстрационный премиум. Создан командой demo:create, не реальная оплата.',
                    ]);
                }
                $credentials[] = [$user->login, $password, $number, $plan === 'premium' ? 'Премиум на 365 дней' : 'Базовая'];
            }
            return $credentials;
        });

        $this->table(['Логин', 'Пароль', 'Табельный номер', 'Подписка'], $credentials);
        $this->info('Сохраните пароли: повторно команда их не показывает. Портал и Telegram не подключены, отчёты не изменены.');
        return self::SUCCESS;
    }
}
