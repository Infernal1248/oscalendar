<?php

namespace App\Console\Commands;

use App\Models\RosterChangeEvent;
use App\Services\Telegram\RosterChangeNotifier;
use App\Services\Telegram\RosterTelegramQueue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, Log};

class NotifyRosterTelegram extends Command
{
    protected $signature = 'telegram:notify-roster';
    protected $description = 'Deliver roster notifications and clean up buttons independently of parser requests';

    public function handle(): int
    {
        if (! config('services.telegram_bot.token')) return self::SUCCESS;
        $lock = Cache::lock('telegram-roster-delivery', 600);
        if (! $lock->get()) return self::SUCCESS;
        try {
            RosterChangeEvent::expirePastPeriods();
            $notifier = app(RosterChangeNotifier::class);
            $deadline = microtime(true) + 40;
            $events = app(RosterTelegramQueue::class)->due()->orderBy('telegram_retry_at')->orderBy('id')->limit(20)->get();
            foreach ($events as $event) {
                if (microtime(true) >= $deadline) break;
                $event->refresh();
                $attempt = $event->telegram_attempts + 1;
                // Persist the retry time before I/O, so an interrupted worker does not spin.
                $event->forceFill(['telegram_attempts' => $attempt,
                    'telegram_retry_at' => now()->addMinutes(min(30, 2 ** min($attempt, 5)))])->save();
                try {
                    $done = true;
                    if ($event->status === 'pending') $done = $notifier->notifyPending($event);
                    if ($event->status === 'acknowledged') $done = $notifier->notifyAcknowledged($event);
                    if (in_array($event->status, ['acknowledgement_requested', 'acknowledged', 'superseded'], true)) {
                        $done = $notifier->removeButtons($event) && $done;
                    }
                    if ($done) $event->forceFill(['telegram_attempts' => 0, 'telegram_retry_at' => null])->save();
                } catch (\Throwable $e) {
                    Log::warning('Roster Telegram delivery deferred', ['event_id' => $event->id, 'exception' => $e::class]);
                }
            }
        } finally {
            $lock->release();
        }
        return self::SUCCESS;
    }
}
