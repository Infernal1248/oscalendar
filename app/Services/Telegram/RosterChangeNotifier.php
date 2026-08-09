<?php

namespace App\Services\Telegram;

use App\Models\RosterChangeEvent;
use App\Models\TelegramAccount;
use Illuminate\Support\Carbon;

class RosterChangeNotifier
{
    public function __construct(private TelegramBotClient $client)
    {
    }

    public function notifyPending(RosterChangeEvent $event): bool
    {
        $this->disableSupersededButtons($event);
        $messages = $event->telegram_messages ?? [];
        $deliveredChats = array_column($messages, 'chat_id');
        $accounts = TelegramAccount::query()->where('user_id', $event->user_id)->get();

        foreach ($accounts as $account) {
            if (in_array((int) $account->telegram_id, $deliveredChats, true)) {
                continue;
            }
            $results = $this->client->sendMessage(
                (int) $account->telegram_id,
                $this->pendingText($event),
                [
                    'reply_markup' => [
                        'inline_keyboard' => [[[
                            'text' => 'Подтвердить ознакомление',
                            'callback_data' => 'roster.ack:'.$event->id,
                        ]]],
                    ],
                ]
            );
            foreach ($results as $result) {
                if (isset($result['message_id'])) {
                    $messages[] = [
                        'chat_id' => (int) $account->telegram_id,
                        'message_id' => (int) $result['message_id'],
                    ];
                    $deliveredChats[] = (int) $account->telegram_id;
                }
            }
        }

        $delivered = $accounts->every(fn (TelegramAccount $account) => in_array(
            (int) $account->telegram_id,
            $deliveredChats,
            true
        ));
        if ($delivered) {
            $event->forceFill([
                'telegram_messages' => $messages,
                'notified_at' => now(),
            ])->save();
        }

        return $delivered;
    }

    public function notifyAcknowledged(RosterChangeEvent $event): bool
    {
        $this->removeButtons($event);
        $accounts = TelegramAccount::query()->where('user_id', $event->user_id)->get();
        $messages = $event->acknowledgement_messages ?? [];
        $deliveredChats = array_column($messages, 'chat_id');
        foreach ($accounts as $account) {
            if (in_array((int) $account->telegram_id, $deliveredChats, true)) {
                continue;
            }
            $results = $this->client->sendMessage(
                (int) $account->telegram_id,
                $this->acknowledgedText($event)
            );
            foreach ($results as $result) {
                if (isset($result['message_id'])) {
                    $messages[] = [
                        'chat_id' => (int) $account->telegram_id,
                        'message_id' => (int) $result['message_id'],
                    ];
                    $deliveredChats[] = (int) $account->telegram_id;
                }
            }
        }
        $delivered = $accounts->every(fn (TelegramAccount $account) => in_array(
            (int) $account->telegram_id,
            $deliveredChats,
            true
        ));
        if ($delivered) {
            $event->forceFill([
                'acknowledgement_messages' => $messages,
                'acknowledgement_notified_at' => now(),
            ])->save();
        }

        return $delivered;
    }

    public function removeButtons(RosterChangeEvent $event): void
    {
        foreach ($event->telegram_messages ?? [] as $message) {
            if (isset($message['chat_id'], $message['message_id'])) {
                $this->client->removeInlineKeyboard((int) $message['chat_id'], (int) $message['message_id']);
            }
        }
    }

    private function disableSupersededButtons(RosterChangeEvent $event): void
    {
        RosterChangeEvent::query()
            ->where('user_id', $event->user_id)
            ->where('source', $event->source)
            ->where('period', $event->period)
            ->where('status', 'superseded')
            ->whereNotNull('telegram_messages')
            ->get()
            ->each(fn (RosterChangeEvent $oldEvent) => $this->removeButtons($oldEvent));
    }

    private function pendingText(RosterChangeEvent $event): string
    {
        $lines = ['<b>Изменено расписание на '.$this->periodName($event->period).'</b>'];
        if (empty($event->changes)) {
            $lines[] = '';
            $lines[] = 'Портал сообщил об изменениях, но не отметил конкретную задачу.';
        }
        foreach ($event->changes as $index => $change) {
            $lines[] = '';
            if ($index > 0) {
                $lines[] = '──────────';
            }
            if (! ($change['previous_available'] ?? false)) {
                $lines[] = '<b>Предыдущие данные неизвестны</b>';
                $lines[] = 'Изменение обнаружено при первой синхронизации.';
                $lines = array_merge($lines, $this->snapshotLines($change['after'] ?? []));
                continue;
            }

            $lines[] = '<b>Задача до изменения:</b>';
            $lines = array_merge($lines, $this->snapshotLines($change['before'] ?? []));
            $lines[] = '';
            $lines[] = '<b>Изменения:</b>';
            if (empty($change['changed_fields'])) {
                $lines[] = 'Портал отметил задачу как изменённую, но значения полей совпадают с последним снимком.';
            }
            foreach ($change['changed_fields'] ?? [] as $field) {
                $lines[] = '';
                $lines[] = '<b>'.$this->e($field['label'] ?? 'Поле').':</b>';
                $lines[] = 'Было: '.$this->formatValue($field['before'] ?? null, $field['label'] ?? null);
                $lines[] = 'Стало: '.$this->formatValue($field['after'] ?? null, $field['label'] ?? null);
            }
        }

        return implode("\n", $lines);
    }

    private function acknowledgedText(RosterChangeEvent $event): string
    {
        $lines = ['<b>Вы подтвердили ознакомление с текущим планом</b>'];
        foreach ($event->changes as $index => $change) {
            $lines[] = '';
            if ($index > 0) {
                $lines[] = '──────────';
            }
            $lines[] = '<b>Актуальная задача:</b>';
            $lines = array_merge($lines, $this->snapshotLines($change['after'] ?? []));
        }

        return implode("\n", $lines);
    }

    private function snapshotLines(array $snapshot): array
    {
        return [
            'Дата и время: '.$this->formatValue($snapshot['starts_at'] ?? null, 'Дата и время'),
            '№ рейса: '.$this->formatValue($snapshot['flight_numbers_raw'] ?? null),
            'Тип ВС: '.$this->formatValue($snapshot['aircraft_type_raw'] ?? null),
            'Борт: '.$this->formatValue($snapshot['boards_raw'] ?? null),
            'Маршрут: '.$this->formatValue($snapshot['route_raw'] ?? null),
        ];
    }

    private function formatValue($value, ?string $label = null): string
    {
        if ($value === null || $value === '') {
            return 'не указано';
        }
        if ($label === 'Дата и время') {
            return Carbon::parse($value)->utc()->format('d.m.Y H:i').' UTC';
        }

        return $this->e((string) $value);
    }

    private function periodName(string $period): string
    {
        $months = [1 => 'январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
        [$year, $month] = array_map('intval', explode('-', $period));
        return ($months[$month] ?? $period).' '.$year;
    }

    private function e(?string $value): string
    {
        return e((string) $value);
    }
}
