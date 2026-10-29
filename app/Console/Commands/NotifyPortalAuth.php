<?php

namespace App\Console\Commands;

use App\Services\{PortalAuthNotifications, WebPushSender};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NotifyPortalAuth extends Command
{
    protected $signature = 'portal:notify-auth';
    protected $description = 'Notify users once after sustained portal authentication failures';

    public function handle(PortalAuthNotifications $notifications, WebPushSender $push): int
    {
        $lock = Cache::lock('portal-auth-notifications', 600);
        if (! $lock->get()) return self::SUCCESS;
        try {
            $notifications->sendDue($push);
        } finally {
            $lock->release();
        }
        return self::SUCCESS;
    }
}
