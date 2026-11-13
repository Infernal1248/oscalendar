<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\{Http, Log};
use RuntimeException;

class BotHubApi
{
    public function baseUrl(): string
    {
        $base = rtrim((string) config('services.telegram_bot.api_base'), '/');
        $parts = parse_url($base);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Invalid bot-hub HTTPS base URL.');
        }
        return $base;
    }

    public function request(string $token, string $method, array $payload = []): array
    {
        if ($token === '' || ! preg_match('/\A[A-Za-z][A-Za-z0-9]{0,63}\z/', $method)) {
            throw new RuntimeException('Bot API credentials or method are missing or invalid.');
        }
        if (in_array($method, ['setWebhook', 'deleteWebhook', 'getUpdates', 'logOut', 'close'], true)) {
            throw new RuntimeException('Manage bot webhooks with bot-hubctl on the VPS.');
        }
        $url = $this->baseUrl().'/bot'.$token.'/'.$method;
        try {
            $response = Http::asJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()->post($url, $payload);
            $data = $response->json();
        } catch (\Throwable $exception) {
            // Transport exceptions can contain the full URL (and therefore the token).
            Log::warning('Telegram API connection failed', ['method' => $method, 'exception' => $exception::class]);
            throw new RuntimeException('Bot-hub transport unavailable.');
        }
        if ($method === 'editMessageReplyMarkup' && $response->status() === 400 && is_array($data)
            && str_contains(strtolower((string) ($data['description'] ?? '')), 'message is not modified')) {
            return ['ok' => true, 'result' => []];
        }
        if (! $response->successful() || ! is_array($data) || ($data['ok'] ?? false) !== true) {
            Log::warning('Telegram API request failed', ['method' => $method, 'status' => $response->status()]);
            throw new RuntimeException('Bot-hub API unavailable or rejected the request.');
        }
        return $data;
    }
}
