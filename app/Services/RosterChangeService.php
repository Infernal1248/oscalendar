<?php

namespace App\Services;

use App\Models\RosterChangeEvent;
use Illuminate\Support\Arr;

class RosterChangeService
{
    private const FIELDS = [
        'starts_at' => 'Дата и время',
        'flight_numbers_raw' => '№ рейса',
        'aircraft_type_raw' => 'Тип ВС',
        'boards_raw' => 'Борт',
        'route_raw' => 'Маршрут',
    ];

    public function recordPending(
        int $userId,
        string $source,
        string $period,
        array $portalState,
        array $markedItems
    ): ?RosterChangeEvent {
        if (! ($portalState['requires_acknowledgement'] ?? false)) {
            return null;
        }

        usort($markedItems, fn (array $left, array $right) => strcmp(
            (string) Arr::get($left, 'after.source_external_id'),
            (string) Arr::get($right, 'after.source_external_id')
        ));
        $hashPayload = [
            'period' => $period,
            'confirmation_text' => $portalState['confirmation_text'] ?? null,
            'items' => array_map(fn (array $item) => $item['after'], $markedItems),
        ];
        $changeHash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = RosterChangeEvent::query()
            ->where('user_id', $userId)
            ->where('source', $source)
            ->where('period', $period)
            ->where('change_hash', $changeHash)
            ->first();
        if ($existing) {
            return $existing->notified_at ? null : $existing;
        }

        RosterChangeEvent::query()
            ->where('user_id', $userId)
            ->where('source', $source)
            ->where('period', $period)
            ->whereIn('status', ['pending', 'acknowledgement_requested'])
            ->update(['status' => 'superseded', 'superseded_at' => now()]);

        $changes = array_map(function (array $item) {
            $before = $item['before'];
            $after = $item['after'];
            $changedFields = [];
            foreach (self::FIELDS as $field => $label) {
                if ($before === null || ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                    $changedFields[$field] = [
                        'label' => $label,
                        'before' => $before[$field] ?? null,
                        'after' => $after[$field] ?? null,
                    ];
                }
            }

            return [
                'source_external_id' => $after['source_external_id'] ?? null,
                'change_type' => $item['change_type'] ?? 'changed',
                'before' => $before,
                'after' => $after,
                'changed_fields' => $changedFields,
                'previous_available' => $before !== null,
            ];
        }, $markedItems);

        return RosterChangeEvent::query()->create([
            'user_id' => $userId,
            'source' => $source,
            'period' => $period,
            'change_hash' => $changeHash,
            'status' => 'pending',
            'changes' => $changes,
            'portal_state' => $portalState,
        ]);
    }
}
