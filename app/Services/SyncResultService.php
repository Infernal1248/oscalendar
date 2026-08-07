<?php

namespace App\Services;

use App\Models\FlightSegment;
use App\Models\PortalCredential;
use App\Models\RosterItem;
use App\Models\SyncRun;
use App\Models\SyncRunPartialChunk;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncResultService
{
    public function __construct(private ParserTaskScheduler $taskScheduler)
    {
    }

    public function store(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $source = $payload['source'];
            $userId = (int) $payload['user_id'];

            Log::info('Sync result transaction started', [
                'sync_run_id' => $payload['sync_run_id'] ?? null,
                'user_id' => $userId,
                'source' => $source,
                'items_found' => count($payload['roster_items'] ?? []),
                'segments_found' => count($payload['flight_segments'] ?? []),
            ]);

            $stats = $this->persistPayload($payload);
            $syncRun = $this->resolveSyncRun($payload, $stats);

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

    public function storePartial(SyncRun $syncRun, array $payload): array
    {
        return DB::transaction(function () use ($syncRun, $payload) {
            $syncRun = SyncRun::query()->lockForUpdate()->findOrFail($syncRun->id);
            $chunkHash = $this->chunkHash($payload);

            $existingChunk = SyncRunPartialChunk::query()
                ->where('sync_run_id', $syncRun->id)
                ->where('chunk_hash', $chunkHash)
                ->first();

            if ($existingChunk) {
                return $this->partialResponse($syncRun, $payload['chunk_kind'], true);
            }

            if (! in_array($syncRun->status, ['queued', 'running'], true)) {
                throw new ConflictHttpException('The sync run is already closed.');
            }

            $chunkStats = $this->persistPayload($payload);

            SyncRunPartialChunk::query()->create([
                'sync_run_id' => $syncRun->id,
                'chunk_hash' => $chunkHash,
                'chunk_kind' => $payload['chunk_kind'],
                'items_found' => $chunkStats['items_found'],
                'segments_found' => $chunkStats['segments_found'],
            ]);

            $stats = [
                'items_found' => $syncRun->items_found + $chunkStats['items_found'],
                'items_created' => $syncRun->items_created + $chunkStats['items_created'],
                'items_updated' => $syncRun->items_updated + $chunkStats['items_updated'],
                'segments_found' => $syncRun->segments_found + $chunkStats['segments_found'],
                'segments_created' => $syncRun->segments_created + $chunkStats['segments_created'],
                'segments_updated' => $syncRun->segments_updated + $chunkStats['segments_updated'],
            ];

            $syncRun->forceFill(array_merge($stats, [
                'status' => 'running',
                'last_chunk_at' => now(),
                'last_chunk_kind' => $payload['chunk_kind'],
                'stats' => array_merge($syncRun->stats ?? [], $stats),
            ]))->save();

            return $this->partialResponse($syncRun, $payload['chunk_kind'], false);
        });
    }

    private function persistPayload(array $payload): array
    {
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
        $rosterByExternalId = [];

        foreach ($payload['roster_items'] ?? [] as $itemPayload) {
            $itemPayload['source_hash'] = empty($itemPayload['source_external_id'])
                ? $this->rosterHash($userId, $source, $itemPayload)
                : null;
            $identity = $this->rosterIdentity($userId, $source, $itemPayload);

            $item = RosterItem::query()->firstOrNew($identity);
            $created = ! $item->exists;
            $item->fill($this->rosterAttributes($userId, $source, $itemPayload));
            $detailsChanged = $created || $item->isDirty([
                'source_external_id',
                'source_request_raw',
                'boards_raw',
                'starts_at',
                'ends_at',
                'is_actual',
                'is_removed_from_source',
            ]);
            $item->save();
            $this->taskScheduler->scheduleFlightDetails($item, $detailsChanged);

            $stats[$created ? 'items_created' : 'items_updated']++;

            if (! empty($item->source_external_id)) {
                $rosterByExternalId[$item->source_external_id] = $item;
            }
        }

        if (($payload['chunk_kind'] ?? null) === 'roster' && ! empty($payload['roster_period'])) {
            $stats['items_updated'] += $this->markMissingRosterItems(
                $userId,
                $source,
                $payload['roster_period'],
                array_keys($rosterByExternalId)
            );
        }

        foreach ($payload['flight_segments'] ?? [] as $segmentPayload) {
            if (empty($segmentPayload['roster_source_external_id']) && ! empty($payload['roster_source_external_id'])) {
                $segmentPayload['roster_source_external_id'] = $payload['roster_source_external_id'];
            }

            $hasIdentity = ! empty($segmentPayload['source_para_id'])
                && ! empty($segmentPayload['flight_number'])
                && ! empty($segmentPayload['starts_at']);
            $segmentPayload['source_hash'] = $hasIdentity
                ? null
                : $this->segmentHash($userId, $source, $segmentPayload);
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

        return $stats;
    }

    private function markMissingRosterItems(int $userId, string $source, string $period, array $seenExternalIds): int
    {
        $start = Carbon::createFromFormat('Y-m', $period, 'UTC')->startOfMonth();
        $end = $start->copy()->addMonth();
        $query = RosterItem::query()
            ->where('user_id', $userId)
            ->where('source', $source)
            ->whereNotNull('source_external_id')
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $end)
            ->where(function ($query) {
                $query->where('is_actual', true)
                    ->orWhere('is_removed_from_source', false);
            });
        if ($seenExternalIds) {
            $query->whereNotIn('source_external_id', $seenExternalIds);
        }

        $items = $query->get();
        foreach ($items as $item) {
            $item->forceFill([
                'is_actual' => false,
                'is_removed_from_source' => true,
            ])->save();
            $this->taskScheduler->scheduleFlightDetails($item, true);
        }

        return $items->count();
    }

    private function partialResponse(SyncRun $syncRun, string $chunkKind, bool $duplicate): array
    {
        return [
            'ok' => true,
            'sync_run_id' => $syncRun->id,
            'chunk_kind' => $chunkKind,
            'stats' => [
                'items_found' => $syncRun->items_found,
                'segments_found' => $syncRun->segments_found,
            ],
            'duplicate' => $duplicate,
        ];
    }

    private function chunkHash(array $payload): string
    {
        return $this->hash($this->canonicalize($payload));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
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
