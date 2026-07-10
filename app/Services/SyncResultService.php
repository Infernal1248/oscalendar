<?php

namespace App\Services;

use App\Models\FlightSegment;
use App\Models\PortalCredential;
use App\Models\RosterItem;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncResultService
{
    public function store(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $source = $payload['source'];
            $userId = (int) $payload['user_id'];
            $stats = [
                'items_found' => count($payload['roster_items'] ?? []),
                'items_created' => 0,
                'items_updated' => 0,
                'segments_found' => count($payload['flight_segments'] ?? []),
                'segments_created' => 0,
                'segments_updated' => 0,
            ];

            Log::info('Sync result transaction started', [
                'sync_run_id' => $payload['sync_run_id'] ?? null,
                'user_id' => $userId,
                'source' => $source,
                'items_found' => $stats['items_found'],
                'segments_found' => $stats['segments_found'],
            ]);

            $syncRun = $this->resolveSyncRun($payload, $stats);
            $rosterByExternalId = [];

            foreach ($payload['roster_items'] ?? [] as $itemPayload) {
                $itemPayload['source_hash'] = $this->rosterHash($userId, $source, $itemPayload);
                $identity = $this->rosterIdentity($userId, $source, $itemPayload);

                $item = RosterItem::query()->firstOrNew($identity);
                $created = ! $item->exists;
                $item->fill($this->rosterAttributes($userId, $source, $itemPayload));
                $item->save();

                $stats[$created ? 'items_created' : 'items_updated']++;

                if (! empty($item->source_external_id)) {
                    $rosterByExternalId[$item->source_external_id] = $item;
                }
            }

            foreach ($payload['flight_segments'] ?? [] as $segmentPayload) {
                $segmentPayload['source_hash'] = $this->segmentHash($userId, $source, $segmentPayload);
                $identity = $this->segmentIdentity($userId, $source, $segmentPayload);

                $segment = FlightSegment::query()->firstOrNew($identity);
                $created = ! $segment->exists;
                $segment->fill($this->segmentAttributes($userId, $source, $segmentPayload, $rosterByExternalId));
                $segment->save();

                $segment->crewMembers()->delete();
                foreach ($segmentPayload['crew'] ?? [] as $crewPayload) {
                    $segment->crewMembers()->create([
                        'role' => $crewPayload['role'] ?? null,
                        'full_name' => $crewPayload['full_name'],
                        'phones' => $crewPayload['phones'] ?? [],
                    ]);
                }

                $segment->deferredItems()->delete();
                foreach ($segmentPayload['deferred_items'] ?? [] as $deferredPayload) {
                    $segment->deferredItems()->create([
                        'group_name' => $deferredPayload['group_name'] ?? null,
                        'title' => $deferredPayload['title'] ?? null,
                        'ata' => $deferredPayload['ata'] ?? null,
                        'work_order' => $deferredPayload['work_order'] ?? null,
                        'due_at' => $this->dateTime($deferredPayload['due_at'] ?? null),
                        'is_warning' => (bool) ($deferredPayload['is_warning'] ?? false),
                        'raw_data' => $deferredPayload['raw_data'] ?? [],
                    ]);
                }

                $stats[$created ? 'segments_created' : 'segments_updated']++;
            }

            $syncRun->forceFill(array_merge($stats, [
                'status' => 'finished',
                'finished_at' => now(),
                'lock_expires_at' => null,
                'locked_by' => null,
                'stats' => $stats,
            ]))->save();

            PortalCredential::query()
                ->where('user_id', $userId)
                ->where('portal', $source)
                ->update([
                    'last_success_at' => now(),
                    'last_error_at' => null,
                    'last_error_text' => null,
                ]);

            return [
                'sync_run_id' => $syncRun->id,
                'status' => $syncRun->status,
                'stats' => $stats,
            ];
        });
    }

    private function resolveSyncRun(array $payload, array $stats): SyncRun
    {
        if (! empty($payload['sync_run_id'])) {
            $syncRun = SyncRun::query()->findOrFail($payload['sync_run_id']);
            $syncRun->forceFill([
                'user_id' => $payload['user_id'],
                'source' => $payload['source'],
                'trigger' => $payload['trigger'] ?? $syncRun->trigger,
            ]);

            return $syncRun;
        }

        return SyncRun::query()->create(array_merge($stats, [
            'user_id' => $payload['user_id'],
            'source' => $payload['source'],
            'trigger' => $payload['trigger'] ?? 'scheduler',
            'status' => 'running',
            'started_at' => $this->dateTime($payload['parsed_at'] ?? null) ?? now(),
        ]));
    }

    private function rosterIdentity(int $userId, string $source, array $payload): array
    {
        if (! empty($payload['source_external_id'])) {
            return [
                'user_id' => $userId,
                'source' => $source,
                'source_external_id' => $payload['source_external_id'],
            ];
        }

        return [
            'user_id' => $userId,
            'source' => $source,
            'source_hash' => $payload['source_hash'],
        ];
    }

    private function segmentIdentity(int $userId, string $source, array $payload): array
    {
        if (! empty($payload['source_para_id']) && ! empty($payload['flight_number']) && ! empty($payload['starts_at'])) {
            return [
                'user_id' => $userId,
                'source' => $source,
                'source_para_id' => $payload['source_para_id'],
                'flight_number' => $payload['flight_number'],
                'starts_at' => $this->dateTime($payload['starts_at']),
            ];
        }

        return [
            'user_id' => $userId,
            'source' => $source,
            'source_hash' => $payload['source_hash'],
        ];
    }

    private function rosterAttributes(int $userId, string $source, array $payload): array
    {
        return [
            'user_id' => $userId,
            'source' => $source,
            'source_external_id' => $payload['source_external_id'] ?? null,
            'source_request_raw' => $payload['source_request_raw'] ?? null,
            'source_hash' => $payload['source_hash'],
            'kind' => $payload['kind'],
            'title' => $payload['title'] ?? null,
            'aircraft_type_raw' => $payload['aircraft_type_raw'] ?? null,
            'flight_numbers_raw' => $payload['flight_numbers_raw'] ?? null,
            'boards_raw' => $payload['boards_raw'] ?? null,
            'route_raw' => $payload['route_raw'] ?? null,
            'starts_at' => $this->dateTime($payload['starts_at']),
            'ends_at' => $this->dateTime($payload['ends_at'] ?? null),
            'is_actual' => (bool) ($payload['is_actual'] ?? true),
            'is_removed_from_source' => (bool) ($payload['is_removed_from_source'] ?? false),
            'source_payload' => $payload['source_payload'] ?? [],
        ];
    }

    private function segmentAttributes(int $userId, string $source, array $payload, array $rosterByExternalId): array
    {
        $rosterItem = null;
        if (! empty($payload['roster_source_external_id'])) {
            $rosterItem = $rosterByExternalId[$payload['roster_source_external_id']]
                ?? RosterItem::query()
                    ->where('user_id', $userId)
                    ->where('source', $source)
                    ->where('source_external_id', $payload['roster_source_external_id'])
                    ->first();
        }

        return [
            'user_id' => $userId,
            'roster_item_id' => $rosterItem ? $rosterItem->id : null,
            'source' => $source,
            'source_para_id' => $payload['source_para_id'] ?? null,
            'source_segment_id' => $payload['source_segment_id'] ?? null,
            'flight_number' => $payload['flight_number'] ?? null,
            'route_raw' => $payload['route_raw'] ?? null,
            'departure_name' => $payload['departure_name'] ?? null,
            'arrival_name' => $payload['arrival_name'] ?? null,
            'aircraft_type' => $payload['aircraft_type'] ?? null,
            'board' => $payload['board'] ?? null,
            'purpose' => $payload['purpose'] ?? null,
            'starts_at' => $this->dateTime($payload['starts_at']),
            'ends_at' => $this->dateTime($payload['ends_at'] ?? null),
            'parking_minutes' => $payload['parking_minutes'] ?? null,
            'dep_stand' => $payload['dep_stand'] ?? null,
            'arr_stand' => $payload['arr_stand'] ?? null,
            'open_doc_url' => $payload['open_doc_url'] ?? null,
            'download_doc_url' => $payload['download_doc_url'] ?? null,
            'next_update_at' => $this->dateTime($payload['next_update_at'] ?? null),
            'source_hash' => $payload['source_hash'],
            'source_payload' => $payload['source_payload'] ?? [],
        ];
    }

    private function rosterHash(int $userId, string $source, array $payload): string
    {
        return $this->hash([
            'user_id' => $userId,
            'source' => $source,
            'kind' => $payload['kind'] ?? null,
            'title' => $payload['title'] ?? null,
            'starts_at' => $this->dateTime($payload['starts_at'] ?? null),
            'source_request_raw' => $payload['source_request_raw'] ?? null,
        ]);
    }

    private function segmentHash(int $userId, string $source, array $payload): string
    {
        return $this->hash([
            'user_id' => $userId,
            'source' => $source,
            'source_segment_id' => $payload['source_segment_id'] ?? null,
            'source_para_id' => $payload['source_para_id'] ?? null,
            'flight_number' => $payload['flight_number'] ?? null,
            'starts_at' => $this->dateTime($payload['starts_at'] ?? null),
            'route_raw' => $payload['route_raw'] ?? null,
        ]);
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function dateTime(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value)
            ->utc()
            ->toDateTimeString();
    }
}
