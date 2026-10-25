<?php

namespace App\Services\Monitoring;

use Illuminate\Http\Request;

class HttpAddressGroups
{
    public static function labels(): array
    {
        return [
            'claim' => 'Выдача задач /api/internal/parser-jobs/claim',
            'node-heartbeat' => 'Heartbeat VM', 'job-heartbeat' => 'Heartbeat задач',
            'parser-results' => 'Результаты парсинга /api/internal/*',
            'monitor' => 'Проверка мониторинга', 'monitor-bot' => 'Команды монитор-бота',
            'telegram' => 'Telegram /api/telegram/*', 'auth' => 'Авторизация /api/auth/*',
            'account' => 'Аккаунт /api/account*', 'workplan' => 'Рабочий план /api/workplan*',
            'history' => 'История изменений', 'reports' => 'Отчёты', 'pilot-checks' => 'Допуски',
            'notifications' => 'Настройки уведомлений /api/notifications*',
            'subscription' => 'Подписки /api/subscription*', 'payments' => 'Оплаты /api/payments/*',
            'npd' => 'Интеграция НПД', 'admin' => 'Администрирование /api/admin/*',
            'calendar' => 'Календарная ссылка /api/calendar/*', 'static' => 'Статические файлы',
            'unknown' => 'Неизвестные адреса (маршрут не найден)',
            'other-api' => 'Остальные API', 'web' => 'Страницы сайта', 'other' => 'Прочие адреса',
        ];
    }

    public static function classify(Request $request): string
    {
        return match (true) {
            $request->is('api/internal/parser-jobs/claim') => 'claim',
            $request->is('api/internal/parser-nodes/heartbeat') => 'node-heartbeat',
            $request->is('api/internal/parser-jobs/*/heartbeat') => 'job-heartbeat',
            $request->is('api/internal/monitor/*') => 'monitor',
            $request->is('api/internal/*') => 'parser-results',
            $request->is('api/telegram/monitor/*') => 'monitor-bot',
            $request->is('api/telegram/*') => 'telegram',
            $request->is('api/auth/*') => 'auth',
            $request->is('api/account', 'api/account/*') => 'account',
            $request->is('api/workplan', 'api/workplan/*') => 'workplan',
            $request->is('api/change-history', 'api/change-history/*') => 'history',
            $request->is('api/airfase', 'api/airfase/*', 'api/green-zone', 'api/green-zone/*', 'api/rrj-express', 'api/rrj-express/*') => 'reports',
            $request->is('api/pilot-checks', 'api/pilot-checks/*') => 'pilot-checks',
            $request->is('api/notifications', 'api/notifications/*') => 'notifications',
            $request->is('api/subscription', 'api/subscription/*') => 'subscription',
            $request->is('api/payments/*') => 'payments',
            $request->is('api/npd', 'api/npd/*') => 'npd',
            $request->is('api/admin', 'api/admin/*') => 'admin',
            $request->is('api/calendar/*') => 'calendar',
            $request->is('assets/*', 'favicon.ico', 'robots.txt')
                || preg_match('/\.(?:js|css|png|jpe?g|svg|ico|webp|woff2?|map)$/i', $request->path()) => 'static',
            $request->route() === null => 'unknown',
            $request->is('api', 'api/*') => 'other-api',
            default => 'web',
        };
    }
}
