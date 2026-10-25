<?php
namespace App\Console\Commands;

use App\Services\PilotCheckReminderQueue;
use App\Services\Telegram\TelegramBotClient;
use App\Services\WebPushSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{Cache, DB, Log};

class NotifyPilotChecks extends Command
{
    protected $signature = 'checks:notify';
    protected $description = 'Send due pilot check reminders to Telegram and browser devices';

    public function handle(WebPushSender $push): int
    {
        $lock = Cache::lock('pilot-check-reminders', 600);
        if (! $lock->get()) return self::SUCCESS;
        try {
            $telegram = config('services.telegram_bot.token') ? app(TelegramBotClient::class) : null;
            $deadline = microtime(true) + 45;
            foreach (app(PilotCheckReminderQueue::class)->dueDeliveries($push, $deadline) as $candidate) {
                ['check' => $check, 'days' => $days, 'channel' => $channel, 'recipient' => $recipient,
                    'target' => $target, 'identity' => $identity, 'delivery' => $delivery] = $candidate;
                if (microtime(true) >= $deadline) return self::SUCCESS;
                $title = $check->title.($check->aircraft_type ? ' · '.$check->aircraft_type : '');
                $body = ($check->origin === 'portal' ? 'Срок допуска' : 'Проверка').': '.$title."\nДата: ".$check->due_on->format('d.m.Y').". Осталось дней: {$days}.";
                // Recheck edits/removals before starting external I/O.
                $current = $check->fresh();
                if (! $current || ! $current->is_current || ! $current->due_on?->equalTo($check->due_on)) continue;
                DB::table('pilot_check_deliveries')->updateOrInsert($identity, [
                    'attempts' => ($delivery->attempts ?? 0) + 1, 'retry_at' => now()->addMinutes(15),
                ]);
                try {
                    $sent = $channel === 'telegram'
                        ? count($telegram->sendMessage((int) $recipient, $body, ['parse_mode' => null])) > 0
                        : $push->send($target, ['title' => 'Проверки и допуски · OSCalendar', 'body' => $body,
                            'tag' => 'check-'.$check->id.'-'.$days, 'url' => '/pilot-checks']) === 'sent';
                    if ($sent) DB::table('pilot_check_deliveries')->where($identity)->update(['sent_at' => now()]);
                } catch (\Throwable $e) {
                    Log::warning('Pilot check reminder deferred', ['check_id' => $check->id, 'channel' => $channel, 'exception' => $e::class]);
                }
            }
        } finally { $lock->release(); }
        return self::SUCCESS;
    }
}
