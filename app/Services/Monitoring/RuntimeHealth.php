<?php

namespace App\Services\Monitoring;

use App\Models\RosterChangeEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RuntimeHealth
{
    public function health(): array
    {
        $last = DB::table('monitor_runtime')->where('key', 'cron')->value('seen_at');
        $installed = DB::table('monitor_runtime')->where('key', 'installed_at')->value('seen_at');
        $baseline = $last ?: $installed;
        $stale = ! $baseline || Carbon::parse($baseline)->lt(now()->subSeconds(config('monitor.cron_stale_seconds')));
        return ['ok' => ! $stale, 'cron_stale' => $stale, 'last_cron_at' => $last,
            'notification_backlog' => $this->notificationBacklog()];
    }

    public function notificationBacklog(): int
    {
        // Only outstanding events with an enabled recipient; never load message contents.
        return RosterChangeEvent::where('period', '>=', now()->utc()->format('Y-m'))
            ->whereHas('user', fn ($q) => $q->where('status', 'active')->where('telegram_notifications_enabled', true))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('telegram_accounts')
                ->whereColumn('telegram_accounts.user_id', 'roster_change_events.user_id'))
            ->where(function ($q) {
                $cutoff = now()->subMinutes(config('monitor.notification_delay_minutes'));
                $q->where(fn ($p) => $p->where('status', 'pending')->whereNull('notified_at')->where('created_at', '<=', $cutoff))
                    ->orWhere(fn ($p) => $p->where('status', 'acknowledged')->whereNull('acknowledgement_notified_at')->where('acknowledged_at', '<=', $cutoff));
            })->count();
    }

    public function statusText(): string
    {
        $health = $this->health();
        $last = $health['last_cron_at'] ? Carbon::parse($health['last_cron_at'])->timezone(config('monitor.timezone'))->format('d.m H:i:s').' Красноярск' : 'ещё не зафиксирован';
        return ($health['cron_stale'] ? '🔴 ' : ($health['last_cron_at'] ? '🟢 ' : '⚠ ')).
            'Последний проход мониторинга cron: '.$last.'. Ожидающих Telegram-событий старше '.config('monitor.notification_delay_minutes').' мин.: '.$health['notification_backlog'].'.';
    }
}
