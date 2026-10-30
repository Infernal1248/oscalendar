<?php

namespace App\Services\Monitoring;

class WarningCategories
{
    public static function labels(): array
    {
        return [
            'internal-auth' => 'Авторизация внутренних API',
            'heartbeat-conflict' => 'Отклонённые heartbeat задач',
            'telegram' => 'Telegram',
            'push' => 'Web push',
            'preflight' => 'Проверка необходимости рассылки',
            'portal-auth' => 'Уведомления об авторизации портала',
            'payments' => 'Платежи',
            'other' => 'Прочие предупреждения',
        ];
    }

    public static function classify(mixed $message): string
    {
        if (! is_string($message)) return 'other';
        return match (true) {
            str_starts_with($message, 'Internal API rejected:') => 'internal-auth',
            $message === 'Parser job heartbeat rejected' => 'heartbeat-conflict',
            str_starts_with($message, 'Pilot check reminder preflight'),
            str_starts_with($message, 'Roster Telegram preflight') => 'preflight',
            str_starts_with($message, 'Telegram '), str_starts_with($message, 'Monitor Telegram'),
            str_starts_with($message, 'Monitor bridge'),
            $message === 'Could not send user approval notification' => 'telegram',
            str_starts_with($message, 'Web push delivery') => 'push',
            str_starts_with($message, 'Portal authentication notice') => 'portal-auth',
            str_starts_with($message, 'YooKassa '), $message === 'Payment notification failed' => 'payments',
            default => 'other',
        };
    }
}
