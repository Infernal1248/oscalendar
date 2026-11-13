<?php

namespace App\Http\Middleware;

use App\Services\Telegram\BotHubApi;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log, Validator};
use RuntimeException;

class BotHubWebhook
{
    public function handle(Request $request, Closure $next, string $tokenConfig)
    {
        $token = (string) config($tokenConfig);
        if ($token === '') return response()->json(['ok' => false], 503);
        if (! hash_equals(hash_hmac('sha256', 'bot-hub-forward-v1', $token), (string) $request->header('X-Bot-Hub-Secret'))) {
            Log::warning('Telegram bot-hub request rejected', ['bot' => $tokenConfig]);
            return response()->json(['ok' => false], 403);
        }
        if ($request->isMethod('GET')) {
            if (! $request->query->has('hub_check')) return response()->json(['ok' => false], 405);
            try {
                $hub = app(BotHubApi::class);
                $data = $hub->request($token, 'getMe');
                $id = $data['result']['id'] ?? null;
                if (! is_int($id) || $id <= 0) throw new RuntimeException('Invalid getMe response.');
                return response()->json(['bot_hub' => 'ready', 'bot_id' => $id, 'api_base' => $hub->baseUrl()])
                    ->header('Cache-Control', 'private, no-store');
            } catch (\Throwable $exception) {
                return response()->json(['ok' => false], 503);
            }
        }
        if (! $request->isMethod('POST')) return response()->json(['ok' => false], 405);
        try {
            // Read the original JSON: global input trimming must not change a portal password.
            $update = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return response()->json(['ok' => false], 400);
        }
        if (! is_array($update) || Validator::make($update, ['update_id' => ['required', 'integer', 'min:0']])->fails()) {
            return response()->json(['ok' => false], 422);
        }
        $request->json()->replace($update);
        $key = ['bot_key' => hash('sha256', explode(':', $token, 2)[0]), 'update_id' => (int) $update['update_id']];
        try {
            // The unique key serializes repeated deliveries. Do not automatically retry a
            // transaction containing outgoing requests: those requests cannot be rolled back.
            return DB::transaction(function () use ($request, $next, $key) {
                DB::table('telegram_webhook_updates')->insertOrIgnore($key + ['created_at' => now()]);
                $receipt = DB::table('telegram_webhook_updates')->where($key)->lockForUpdate()->first();
                if ($receipt->processed_at !== null) return response()->json(['ok' => true]);
                $response = $next($request);
                if ($response->getStatusCode() >= 400) throw new RuntimeException('Telegram update processing failed.');
                DB::table('telegram_webhook_updates')->where($key)->update(['processed_at' => now()]);
                return $response;
            });
        } catch (\Throwable $exception) {
            Log::warning('Telegram bot-hub update deferred', ['bot' => $tokenConfig, 'update_id' => $key['update_id'], 'exception' => $exception::class]);
            return response()->json(['ok' => false], 503);
        }
    }
}
