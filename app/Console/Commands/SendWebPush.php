<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\RosterChangeEvent;
use App\Services\WebPushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SendWebPush extends Command
{
    protected $signature = 'push:send';
    protected $description = 'Send recent roster notifications to opted-in browser devices';

    public function handle(WebPushSender $sender): int
    {
        if (! $sender->configured()) return self::SUCCESS;
        $lock = Cache::lock('webpush:send', 600);
        if (! $lock->get()) return self::SUCCESS;
        try {
            $deadline = microtime(true) + 45;
            foreach (PushSubscription::with('user.roles')->orderBy('id')->lazyById(100) as $subscription) {
                if (! $subscription->user?->hasPermission('history.view')) continue;
                foreach (['pending' => 'created_at', 'acknowledged' => 'acknowledged_at'] as $kind => $date) {
                    $events = RosterChangeEvent::where('user_id', $subscription->user_id)
                        ->where('status', $kind)->where('period', '>=', now('UTC')->format('Y-m'))
                        ->where($date, '>=', $subscription->created_at)->where($date, '>=', now()->subDay())
                        ->orderBy('id')->get();
                    foreach ($events as $event) {
                        $identity = ['push_subscription_id' => $subscription->id, 'roster_change_event_id' => $event->id, 'kind' => $kind];
                        $delivery = DB::table('push_deliveries')->where($identity)->first();
                        if ($delivery && ($delivery->sent_at || $delivery->attempts >= 5
                            || ($delivery->attempted_at && $delivery->attempted_at > now()->subMinutes(5)->toDateTimeString()))) continue;
                        if (microtime(true) >= $deadline) return self::SUCCESS;
                        DB::table('push_deliveries')->updateOrInsert($identity, [
                            'attempts' => ($delivery->attempts ?? 0) + 1, 'attempted_at' => now(),
                            'created_at' => $delivery->created_at ?? now(), 'updated_at' => now(),
                        ]);
                        $result = $sender->send($subscription, [
                            'title' => 'OSCalendar',
                            'body' => $kind === 'pending' ? 'В рабочем плане появились изменения. Откройте хронологию для ознакомления.'
                                : 'Ознакомление с изменениями рабочего плана подтверждено.',
                            'tag' => 'roster-'.$event->user_id.'-'.$event->period,
                            'url' => '/history',
                        ]);
                        if ($result === 'expired') continue 3;
                        if ($result === 'sent') DB::table('push_deliveries')->where($identity)->update(['sent_at' => now()]);
                    }
                }
            }
            DB::table('push_deliveries')->where('created_at', '<', now()->subDays(30))->delete();
        } finally {
            $lock->release();
        }
        return self::SUCCESS;
    }
}
