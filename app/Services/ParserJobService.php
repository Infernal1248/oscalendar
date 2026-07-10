<?php

namespace App\Services;

use App\Models\PortalCredential;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParserJobService
{
    public function claim(array $options): ?array
    {
        return DB::transaction(function () use ($options) {
            $source = $options['source'] ?? 'rossiya_edu';
            $portal = $options['portal'] ?? $source;
            $lockedBy = $options['locked_by'] ?? (gethostname() ?: 'parser');
            $lockSeconds = (int) ($options['lock_seconds'] ?? 900);
            $userId = $options['user_id'] ?? null;
            $now = now();

            $syncRun = $this->claimQueuedRun($source, $lockedBy, $lockSeconds, $now, $userId)
                ?: $this->createAndClaimRun($source, $portal, $lockedBy, $lockSeconds, $now, $userId);

            if (! $syncRun) {
                Log::info('Parser job service found no claimable user', [
                    'source' => $source,
                    'portal' => $portal,
                    'user_id' => $userId,
                ]);

                return null;
            }

            $credential = PortalCredential::query()
                ->where('user_id', $syncRun->user_id)
                ->where('portal', $portal)
                ->where('status', 'active')
                ->first();

            if (! $credential) {
                Log::warning('Parser job failed: active credentials missing after claim', [
                    'sync_run_id' => $syncRun->id,
                    'user_id' => $syncRun->user_id,
                    'portal' => $portal,
                ]);

                $syncRun->forceFill([
                    'status' => 'failed',
                    'finished_at' => $now,
                    'error_text' => 'Active portal credentials not found.',
                ])->save();

                return null;
            }

            return [
                'sync_run_id' => $syncRun->id,
                'user_id' => $syncRun->user_id,
                'source' => $syncRun->source,
                'portal' => $credential->portal,
                'login' => $credential->login,
                'password' => Crypt::decryptString($credential->password_encrypted),
                'attempt' => $syncRun->attempt,
                'locked_by' => $syncRun->locked_by,
                'lock_expires_at' => optional($syncRun->lock_expires_at)->toIso8601String(),
            ];
        });
    }

    public function heartbeat(SyncRun $syncRun, array $data): SyncRun
    {
        $lockSeconds = (int) ($data['lock_seconds'] ?? 900);

        $syncRun->forceFill([
            'heartbeat_at' => now(),
            'lock_expires_at' => now()->addSeconds($lockSeconds),
            'locked_by' => $data['locked_by'] ?? $syncRun->locked_by,
        ])->save();

        return $syncRun;
    }

    private function claimQueuedRun(string $source, string $lockedBy, int $lockSeconds, Carbon $now, ?int $userId): ?SyncRun
    {
        $query = SyncRun::query()
            ->where('source', $source)
            ->whereIn('status', ['queued', 'running'])
            ->where(function ($query) use ($now) {
                $query->where('status', 'queued')
                    ->orWhere(function ($query) use ($now) {
                        $query->where('status', 'running')
                            ->whereNotNull('lock_expires_at')
                            ->where('lock_expires_at', '<', $now);
                    });
            })
            ->orderBy('created_at');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $syncRun = $query->lockForUpdate()->first();

        if (! $syncRun) {
            Log::info('Parser job service found no queued or expired run', [
                'source' => $source,
                'user_id' => $userId,
            ]);

            return null;
        }

        $syncRun->forceFill([
            'status' => 'running',
            'claimed_at' => $now,
            'started_at' => $syncRun->started_at ?: $now,
            'heartbeat_at' => $now,
            'lock_expires_at' => $now->copy()->addSeconds($lockSeconds),
            'locked_by' => $lockedBy,
            'attempt' => $syncRun->attempt + 1,
        ])->save();

        return $syncRun;
    }

    private function createAndClaimRun(string $source, string $portal, string $lockedBy, int $lockSeconds, Carbon $now, ?int $userId): ?SyncRun
    {
        $credentialQuery = PortalCredential::query()
            ->select('portal_credentials.*')
            ->join('users', 'users.id', '=', 'portal_credentials.user_id')
            ->where('users.status', 'active')
            ->where('portal_credentials.portal', $portal)
            ->where('portal_credentials.status', 'active')
            ->whereNotExists(function ($query) use ($source) {
                $query->select(DB::raw(1))
                    ->from('sync_runs')
                    ->whereColumn('sync_runs.user_id', 'portal_credentials.user_id')
                    ->where('sync_runs.source', $source)
                    ->whereIn('sync_runs.status', ['queued', 'running']);
            })
            ->orderBy('portal_credentials.last_success_at')
            ->orderBy('portal_credentials.updated_at');

        if ($userId) {
            $credentialQuery->where('portal_credentials.user_id', $userId);
        }

        $credential = $credentialQuery->lockForUpdate()->first();

        if (! $credential) {
            Log::info('Parser job service found no ready credentials', [
                'source' => $source,
                'portal' => $portal,
                'user_id' => $userId,
            ]);

            return null;
        }

        return SyncRun::query()->create([
            'user_id' => $credential->user_id,
            'source' => $source,
            'trigger' => 'scheduler',
            'status' => 'running',
            'started_at' => $now,
            'claimed_at' => $now,
            'heartbeat_at' => $now,
            'lock_expires_at' => $now->copy()->addSeconds($lockSeconds),
            'locked_by' => $lockedBy,
            'attempt' => 1,
        ]);
    }
}
