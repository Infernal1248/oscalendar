<?php

namespace App\Services\Telegram;

use App\Models\RosterChangeEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RosterTelegramQueue
{
    public function due(): Builder
    {
        return RosterChangeEvent::where(fn ($q) => $q->whereNull('telegram_retry_at')->orWhere('telegram_retry_at', '<=', now()))
            ->where(function ($q) {
                $q->where(fn ($p) => $p->where('status', 'pending')->whereNull('notified_at'))
                    ->orWhere(fn ($p) => $p->where('status', 'acknowledged')->whereNull('acknowledgement_notified_at'))
                    ->orWhere(fn ($p) => $p->whereIn('status', ['acknowledgement_requested', 'acknowledged', 'superseded'])
                        ->whereNull('telegram_buttons_removed_at')->whereNotNull('telegram_messages'));
            });
    }

    public function shouldRun(): bool
    {
        if (! config('services.telegram_bot.token')) return false;
        try {
            // Expiry must still run in idle months: it can create button-cleanup work.
            RosterChangeEvent::expirePastPeriods();
            return $this->due()->exists();
        } catch (\Throwable $exception) {
            // A preflight failure must not abort the scheduler or suppress delivery.
            // Fall back to the previous behavior: let the background command retry.
            try {
                Log::warning('Roster Telegram preflight unavailable; starting delivery command', [
                    'exception' => $exception::class,
                ]);
            } catch (\Throwable $loggingException) {
                // Logging errors must not block the remaining scheduled commands.
            }
            return true;
        }
    }
}
