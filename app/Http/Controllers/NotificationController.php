<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function show(Request $request, WebPushSender $sender): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403);
        return response()->json([
            'telegram_enabled' => $request->user()->telegram_notifications_enabled ?? true,
            'telegram_connected' => $request->user()->telegramAccounts()->exists(),
            'push_available' => $sender->configured(),
            'public_key' => $sender->configured() ? config('webpush.public_key') : null,
            'can_notify' => $request->user()->hasPermission('history.view'),
            'devices' => PushSubscription::where('user_id', $request->user()->id)->get(['id', 'endpoint_hash'])
                ->map(fn ($device) => ['id' => $device->id, 'endpoint_hash' => $device->endpoint_hash]),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, WebPushSender $sender): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403);
        $data = $request->validate(['telegram_enabled' => ['required', 'boolean']]);
        $request->user()->forceFill(['telegram_notifications_enabled' => $data['telegram_enabled']])->save();
        return $this->show($request, $sender);
    }

    public function subscribe(Request $request, WebPushSender $sender): JsonResponse
    {
        abort_unless($request->user()->hasPermission('history.view'), 403);
        abort_unless($sender->configured(), 503, 'Push-уведомления ещё не настроены на сервере.');
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', function ($attribute, $value, $fail) {
                $url = parse_url($value);
                $host = strtolower($url['host'] ?? '');
                $allowed = $host === 'fcm.googleapis.com';
                foreach (['push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'] as $domain) {
                    $allowed = $allowed || $host === $domain || str_ends_with($host, '.'.$domain);
                }
                if (! $allowed || ($url['scheme'] ?? '') !== 'https' || ($url['port'] ?? 443) !== 443
                    || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
                    $fail('Не поддерживается адрес службы push-уведомлений этого браузера.');
                }
            }],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{87}=?\z/'],
            'keys.auth' => ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{22}(==)?\z/'],
        ]);
        $id = DB::transaction(function () use ($request, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $data['endpoint']);
            $device = PushSubscription::where('endpoint_hash', $hash)->first();
            abort_if($device && $device->user_id !== $request->user()->id, 409, 'Это устройство подключено к другому аккаунту. Отключите его push-подписку и повторите.');
            abort_if(! $device && PushSubscription::where('user_id', $request->user()->id)->count() >= 10, 422, 'Достигнут лимит в 10 устройств.');
            return PushSubscription::updateOrCreate(['endpoint_hash' => $hash], [
                'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'], 'keys' => $data['keys'],
            ])->id;
        });
        return response()->json(['id' => $id]);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        abort_unless($request->user()->status === 'active', 403);
        PushSubscription::where('user_id', $request->user()->id)->findOrFail($device)->delete();
        return response()->json(['ok' => true]);
    }

    public function test(Request $request, int $device, WebPushSender $sender): JsonResponse
    {
        abort_unless($request->user()->hasPermission('history.view'), 403);
        abort_unless($sender->configured(), 503, 'Push-уведомления ещё не настроены на сервере.');
        $subscription = PushSubscription::where('user_id', $request->user()->id)->findOrFail($device);
        $result = $sender->send($subscription, [
            'title' => 'OSCalendar', 'body' => 'Уведомления на этом устройстве включены.',
            'tag' => 'oscalendar-test', 'url' => '/profile',
        ]);
        abort_unless($result === 'sent', 502, $result === 'expired'
            ? 'Подписка устройства устарела. Включите push заново.' : 'Не удалось отправить push. Попробуйте позже.');
        return response()->json(['ok' => true]);
    }
}
