<?php

namespace App\Services\Monitoring;

use App\Services\Telegram\MonitorBotClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MonitorDigest
{
    public function sendDue(): void
    {
        if (! config('monitor.summary_enabled') || ! config('monitor.token') || ! config('monitor.admin_ids')) return;
        $local = now()->timezone(config('monitor.timezone'));
        $slots = [];
        foreach (config('monitor.summary_times') as $time) {
            foreach ([0, 1] as $daysAgo) {
                $slot = $local->copy()->subDays($daysAgo)->setTimeFromTimeString($time)->startOfMinute();
                if ($slot->lte($local)) $slots[] = $slot;
            }
        }
        if (! $slots) return;
        usort($slots, fn ($a, $b) => $b->timestamp <=> $a->timestamp);
        $slot = $slots[0]->utc();
        // Retry for two hours after the slot; don't flood old reports after downtime.
        if ($slot->lt(now()->subHours(2))) return;
        foreach (config('monitor.admin_ids') as $chatId) {
            $lock = Cache::lock('monitor:digest:'.$chatId.':'.$slot->timestamp, 180);
            if (! $lock->get()) continue;
            try {
                $identity = ['chat_id' => (string) $chatId, 'slot_at' => $slot->toDateTimeString()];
                $delivery = DB::table('monitor_report_deliveries')->where($identity)->first();
                if ($delivery?->sent_at) continue;
                if (! $delivery) {
                    DB::table('monitor_report_deliveries')->insertOrIgnore(array_merge($identity, [
                        'body' => app(MonitorHistory::class)->summary($slot), 'next_chunk' => 0,
                    ]));
                    $delivery = DB::table('monitor_report_deliveries')->where($identity)->first();
                }
                $chunks = mb_str_split($delivery->body, 3500);
                foreach ($chunks as $i => $chunk) {
                    if ($i < $delivery->next_chunk) continue;
                    app(MonitorBotClient::class)->send((string) $chatId, $chunk);
                    DB::table('monitor_report_deliveries')->where($identity)->update(['next_chunk' => $i + 1]);
                }
                DB::table('monitor_report_deliveries')->where($identity)->update(['sent_at' => now()]);
            } catch (\Throwable $exception) {
                // Per-recipient retry on the next minute; don't log provider URLs/tokens.
            } finally {
                $lock->release();
            }
        }
    }
}
