<?php

namespace App\Services;

use App\Models\{PortalCredential, PushSubscription, SyncRun};
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{DB, Log};

class PortalAuthNotifications
{
    public static function resetState(): array
    {
        return ['auth_failure_started_at' => null, 'auth_failure_last_at' => null, 'auth_failure_count' => 0,
            'auth_notified_at' => null, 'auth_notification_retry_at' => null];
    }

    /** Called inside the run completion transaction, once per completed run. */
    public function record(SyncRun $run): void
    {
        if (! $run->user_id || ! in_array($run->status, ['finished', 'failed'], true)) return;
        $credential = PortalCredential::where('user_id', $run->user_id)->where('portal', $run->source)->lockForUpdate()->first();
        if (! $credential) return;
        // A worker may still be using the previous credentials after an edit.
        if ($credential->auth_credentials_changed_at && $run->started_at?->lte($credential->auth_credentials_changed_at)) return;
        $finished = $run->finished_at ?? now();
        // Late results from older runs must not undo a newer success/failure.
        $lastResult = collect([$credential->last_success_at, $credential->last_error_at])->filter()->max();
        if ($lastResult && $finished->lt($lastResult)) return;
        if ($run->status === 'finished') {
            $credential->forceFill(self::resetState() + ['last_success_at' => $finished,
                'last_error_at' => null, 'last_error_text' => null])->save();
            return;
        }
        $attributes = ['last_error_at' => $finished, 'last_error_text' => $run->error_text];
        if (PortalAuthFailure::matches($run->error_text)) {
            // Ignore an overlapping attempt that started before another VM succeeded.
            if ($credential->last_success_at && $run->started_at?->lte($credential->last_success_at)) return;
            $gap = max(1, (int) config('parser.auth_failure_max_gap_hours', 2));
            if (! $credential->auth_failure_last_at || $credential->auth_failure_last_at->lt($finished->copy()->subHours($gap))) {
                $attributes += self::resetState();
                $attributes['auth_failure_started_at'] = $finished;
                $attributes['auth_failure_count'] = 1;
            } else {
                $attributes['auth_failure_count'] = $credential->auth_failure_count + 1;
            }
            $attributes['auth_failure_last_at'] = $finished;
        } else {
            // Mixed server/network failures are not evidence of continuously bad credentials.
            $attributes += self::resetState();
        }
        $credential->forceFill($attributes)->save();
    }

    public function due(): Builder
    {
        return PortalCredential::where('status', 'active')->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->where('auth_failure_started_at', '<=', now()->subHours(max(1, (int) config('parser.auth_failure_notify_hours', 24))))
            ->where('auth_failure_last_at', '>=', now()->subHours(max(1, (int) config('parser.auth_failure_max_gap_hours', 2))))
            ->where('auth_failure_count', '>=', max(2, (int) config('parser.auth_failure_min_attempts', 5)))
            ->whereNull('auth_notified_at')->where(fn ($q) => $q->whereNull('auth_notification_retry_at')->orWhere('auth_notification_retry_at', '<=', now()));
    }

    public function sendDue(WebPushSender $push): void
    {
        $deadline = microtime(true) + 45;
        foreach ($this->due()->lazyById(100) as $candidate) {
            if (microtime(true) >= $deadline) return;
            $credential = DB::transaction(function () use ($candidate) {
                $current = $this->due()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $current) return null;
                $current->forceFill(['auth_notification_retry_at' => now()->addHour()])->save();
                return $current;
            });
            if (! $credential) continue;
            $body = 'OSCalendar длительное время не может войти в ваш аккаунт OpenSky. '
                .'Откройте профиль в личном кабинете OSCalendar и обновите логин и пароль в разделе «Подключение OpenSky». '
                .'До восстановления доступа рабочий план может не обновляться.';
            $profileUrl = rtrim((string) (config('yookassa.frontend_url') ?: config('app.url')), '/').'/profile#opensky';
            try {
                $sent = false;
                $user = $credential->user;
                if ($user->login === null && $user->role === 'user') {
                    $body .= "\n\nДля входа в OSCalendar используйте прежние логин и пароль, ранее сохранённые в OSCalendar. "
                        .'После обновления данных OpenSky в профиле вход в OSCalendar тоже будет по новым данным. '
                        .'Если войти не получается, обновите пароль через привязанный Telegram-бот: «Сменить пароль».';
                }
                $body .= "\n\n".$profileUrl;
                if (config('services.telegram_bot.token') && $user->telegram_notifications_enabled) {
                    foreach ($user->telegramAccounts as $account) {
                        if (! $this->stillCurrent($credential)) break;
                        if (count(app(TelegramBotClient::class)->sendMessage($account->telegram_id, $body, [
                            'parse_mode' => null, 'reply_markup' => ['inline_keyboard' => [[
                                ['text' => 'Обновить логин и пароль', 'url' => $profileUrl],
                            ]]],
                        ])) > 0) {
                            $sent = true;
                            break;
                        }
                        if (microtime(true) >= $deadline) break;
                    }
                }
                if (! $sent && microtime(true) < $deadline && $push->configured()) {
                    foreach (PushSubscription::where('user_id', $user->id)->get() as $device) {
                        if (! $this->stillCurrent($credential)) break;
                        if ($push->send($device, ['title' => 'Проверьте подключение OpenSky', 'body' => $body,
                            'tag' => 'portal-auth-'.$credential->id, 'url' => '/profile#opensky']) === 'sent') {
                            $sent = true;
                            break;
                        }
                        if (microtime(true) >= $deadline) break;
                    }
                }
                if ($sent) PortalCredential::whereKey($credential->id)
                    ->where('auth_failure_started_at', $credential->auth_failure_started_at)
                    ->update(['auth_notified_at' => now()]);
            } catch (\Throwable $exception) {
                Log::warning('Portal authentication notice deferred', ['credential_id' => $credential->id, 'exception' => $exception::class]);
            }
        }
    }

    private function stillCurrent(PortalCredential $credential): bool
    {
        return PortalCredential::whereKey($credential->id)->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->where('auth_failure_started_at', $credential->auth_failure_started_at)->whereNull('auth_notified_at')->exists();
    }
}
