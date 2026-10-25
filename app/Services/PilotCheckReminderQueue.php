<?php

namespace App\Services;

use App\Models\{PilotCheck, PushSubscription, TelegramAccount};
use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\{DB, Log};

class PilotCheckReminderQueue
{
    /** Shared eligibility and delivery state for preflight and actual sending. */
    public function dueDeliveries(WebPushSender $push, ?float $deadline = null, bool $failOnDeadline = false): Generator
    {
        $telegramEnabled = (bool) config('services.telegram_bot.token');
        $pushEnabled = $push->configured();
        if (! $telegramEnabled && ! $pushEnabled) return;
        foreach (PilotCheck::with('user.roles')->where('is_current', true)->whereNotNull('due_on')
            ->whereBetween('due_on', [now('UTC')->subDay()->toDateString(), now('UTC')->addDays(16)->toDateString()])->lazyById(100) as $check) {
            if ($this->pastDeadline($deadline, $failOnDeadline)) return;
            if (! $check->isVisibleCheck()) continue;
            $user = $check->user;
            if (! $user?->hasReportsAccess() || ! $user->check_notifications_enabled) continue;
            $today = now($user->timezone ?: 'UTC')->toDateString();
            $days = (int) Carbon::parse($today, 'UTC')->diffInDays(Carbon::parse($check->due_on->toDateString(), 'UTC'), false);
            if (! in_array($days, [14, 10, 7, 3], true)) continue;
            $recipients = [];
            if ($telegramEnabled && $user->telegram_notifications_enabled) {
                foreach (TelegramAccount::where('user_id', $user->id)->get() as $account) {
                    $recipients[] = ['telegram', (string) $account->telegram_id, $account];
                }
            }
            if ($pushEnabled) {
                foreach (PushSubscription::where('user_id', $user->id)->get() as $device) {
                    $recipients[] = ['push', (string) $device->id, $device];
                }
            }
            foreach ($recipients as [$channel, $recipient, $target]) {
                if ($this->pastDeadline($deadline, $failOnDeadline)) return;
                $identity = ['pilot_check_id' => $check->id, 'due_on' => $check->due_on->toDateString(),
                    'days_before' => $days, 'channel' => $channel, 'recipient' => $recipient];
                $delivery = DB::table('pilot_check_deliveries')->where($identity)->first();
                if ($delivery && ($delivery->sent_at || ($delivery->retry_at && $delivery->retry_at > now()->toDateTimeString()))) continue;
                yield compact('check', 'days', 'channel', 'recipient', 'target', 'identity', 'delivery');
            }
        }
    }

    public function shouldRun(): bool
    {
        try {
            foreach ($this->dueDeliveries(app(WebPushSender::class), microtime(true) + 1, true) as $candidate) return true;
            return false;
        } catch (\Throwable $exception) {
            // A slow or failed preflight must not suppress reminders or abort cron.
            try {
                Log::warning('Pilot check reminder preflight unavailable; starting delivery command', ['exception' => $exception::class]);
            } catch (\Throwable $loggingException) {
                // Logging must not block other scheduled tasks.
            }
            return true;
        }
    }

    private function pastDeadline(?float $deadline, bool $failOnDeadline): bool
    {
        if ($deadline === null || microtime(true) < $deadline) return false;
        if ($failOnDeadline) throw new \RuntimeException('Reminder preflight time budget exceeded.');
        return true;
    }
}
