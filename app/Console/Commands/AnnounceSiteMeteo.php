<?php

namespace App\Console\Commands;

use App\Models\TelegramAccount;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AnnounceSiteMeteo extends Command
{
    protected $signature = 'telegram:announce-site-meteo {--send : Отправить объявление}';
    protected $description = 'Разово отправить объявление о сайте OSCalendar и разделе «Метео»';

    private const MESSAGE = <<<'HTML'
<b>OSCalendar теперь и в браузере</b>

В <a href="https://oscalendar.ru/">личном кабинете</a> можно посмотреть ближайшие события на дашборде, открыть «Рабочий план» и «Хронологию изменений», а в профиле — подключить календарь.

С расширенной подпиской доступны <b>«Налёт»</b>, <b>«Статистика»</b>, <b>«Проверки и допуски»</b>, а также отчёты <b>AirFASE</b>, <b>«Зелёная зона»</b> и <b>RRJ-EXPRESS</b>.

<b>Уведомления в браузере.</b> Если оповещения в Telegram не приходят, откройте <a href="https://oscalendar.ru/profile">профиль</a> в браузере. В разделе «Уведомления» включите «Push на этом устройстве».

<b>Новое — «Метео» на сайте и в боте.</b> Откройте <a href="https://oscalendar.ru/meteo">раздел «Метео»</a> или нажмите «Метео» в меню бота. Можно запросить свежие сводки сразу по нескольким аэропортам. Например, введите <code>UUEE LED SVX</code>.

Ручные метеозапросы доступны всем активным пользователям, даже без подписки.
HTML;

    public function handle(): int
    {
        $recipients = TelegramAccount::query()->where('telegram_id', '>', 0)
            ->whereHas('user', fn ($query) => $query->where('status', 'active')
                ->where('telegram_notifications_enabled', true));

        $this->info('Получателей с включёнными уведомлениями: '.$recipients->count());
        if (! $this->option('send')) {
            $this->line('Предпросмотр. Для отправки добавьте --send.');
            return self::SUCCESS;
        }

        $bot = app(TelegramBotClient::class);
        $directory = storage_path('app/private/telegram-announcements');
        File::ensureDirectoryExists($directory, 0700);
        $journal = fopen($directory.'/site-meteo.txt', 'a+');
        if ($journal === false) {
            $this->error('Не удалось открыть журнал отправки.');
            return self::FAILURE;
        }

        try {
            if (! flock($journal, LOCK_EX | LOCK_NB)) {
                $this->error('Эта рассылка уже запущена.');
                return self::FAILURE;
            }
            $history = stream_get_contents($journal, -1, 0);
            if ($history === false || ($history !== '' && ! preg_match('/\A(?:[0-9]+\n)+\z/', $history))) {
                $this->error('Не удалось прочитать журнал отправки.');
                return self::FAILURE;
            }
            $delivered = array_fill_keys(explode("\n", trim($history)), true);
            $sent = $skipped = $failed = 0;

            foreach ($recipients->lazyById(100, 'id') as $account) {
                $chatId = $account->telegram_id;
                if (isset($delivered[$chatId])) {
                    $skipped++;
                    continue;
                }

                try {
                    $messages = $bot->sendMessage($chatId, self::MESSAGE);
                    if (! isset($messages[0]['message_id'])) {
                        throw new RuntimeException('Telegram did not confirm the send.');
                    }
                } catch (RuntimeException $exception) {
                    $this->warn('Отправка не подтверждена для привязки #'.$account->id.'.');
                    $failed++;
                    usleep(150_000);
                    continue;
                }

                $receipt = $chatId."\n";
                if (fwrite($journal, $receipt) !== strlen($receipt) || ! fflush($journal) || ! fsync($journal)) {
                    $this->error('Telegram подтвердил отправку, но журнал не сохранился для привязки #'.$account->id.'. Рассылка остановлена.');
                    return self::FAILURE;
                }
                $sent++;
                usleep(150_000);
            }

            $this->info("Отправлено: {$sent}. Уже получили: {$skipped}. Без подтверждения: {$failed}.");
            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        } finally {
            fclose($journal);
        }
    }
}
