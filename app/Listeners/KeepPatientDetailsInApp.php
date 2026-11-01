<?php

namespace App\Listeners;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\HipaaActivityNotice;
use App\Services\Compliance\Hipaa;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Cache;

/**
 * HIPAA mode (D117): an alert that would carry patient details by email, text or push is stopped;
 * it stays in the app's own bell, and the person gets a plain "new activity" note instead, at most
 * every 10 minutes per way of reaching them.
 */
class KeepPatientDetailsInApp
{
    private const CHANNELS = ['mail', 'sms', 'push'];

    public function handle(NotificationSending $event): ?bool
    {
        $notification = $event->notification;
        $user = $event->notifiable;
        if (! in_array($event->channel, self::CHANNELS, true) || $notification instanceof HipaaActivityNotice || ! $user instanceof User
            || ! method_exists($notification, 'toArray')) {
            return null;
        }
        $data = (array) $notification->toArray($user);
        $organizationId = isset($data['organization_id']) ? (int) $data['organization_id'] : null;
        if (! $organizationId || ! Hipaa::sensitive(isset($data['event']) ? (string) $data['event'] : null) || ! Hipaa::on($organizationId)) {
            return null;
        }
        if (Cache::add('hipaa-notice:'.$user->id.':'.$event->channel, true, now()->addMinutes(10))) {
            $user->notify(new HipaaActivityNotice((string) Organization::query()->whereKey($organizationId)->value('name'), [$event->channel]));
        }

        return false;
    }
}
