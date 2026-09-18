<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushSender
{
    public function configured(): bool
    {
        return (bool) (config('webpush.public_key') && config('webpush.private_key') && config('webpush.subject'));
    }

    public function send(PushSubscription $subscription, array $payload): string
    {
        try {
            $report = $this->client()->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint, 'keys' => $subscription->keys,
                'contentEncoding' => 'aes128gcm',
            ]), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if ($report->isSuccess()) return 'sent';
            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
                return 'expired';
            }
            Log::warning('Web push delivery failed', [
                'subscription_id' => $subscription->id,
                'status' => $report->getResponse()?->getStatusCode(),
            ]);
        } catch (\Throwable $exception) {
            // Provider errors can contain the private endpoint; log only the exception class.
            Log::warning('Web push delivery failed', ['subscription_id' => $subscription->id, 'exception' => $exception::class]);
        }
        return 'failed';
    }

    protected function client(): WebPush
    {
        return new WebPush(['VAPID' => [
            'subject' => config('webpush.subject'),
            'publicKey' => config('webpush.public_key'),
            'privateKey' => config('webpush.private_key'),
        ]], ['TTL' => 3600], 10, ['allow_redirects' => false]);
    }
}
