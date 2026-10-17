<?php

namespace App\Notifications\Channels;

use App\Models\FcmToken;
use App\Models\User;
use App\Services\FcmService;
use App\Support\Mobile\AppLink;
use App\Support\NotificationChannels;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Notifications to the person's phones with the SureHelp app signed in (D63). The app opens the
 * link in `data.url`; `data.event` lets it group them.
 */
class PushChannel
{
    public function __construct(private readonly FcmService $fcm) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! NotificationChannels::enabled('push')) {
            return;
        }
        $tokens = FcmToken::query()->where('user_id', $notifiable->id)->pluck('token')->all();
        if ($tokens === []) {
            return;
        }

        $message = self::message($notifiable, $notification);
        // The app opens the record from `route` and `route_params` (API-2), and marks it read by `notification_id`.
        $target = AppLink::resolve($message['url']);
        $this->fcm->sendNotification($tokens, ['title' => $message['title'], 'body' => $message['body']], array_filter([
            'url' => $message['url'],
            'event' => $message['event'],
            'notification_id' => (string) $notification->id,
            'route' => $target['route'] ?? null,
            'route_params' => $target ? (string) json_encode($target['params']) : null,
        ]));
    }

    /**
     * The short form of any notification: from its own toPush()/toSms() when it has one, else its
     * in-app payload (title, body, url).
     *
     * @return array{title: string, body: string, url: ?string, event: ?string}
     */
    public static function message(object $notifiable, Notification $notification): array
    {
        $data = method_exists($notification, 'toArray') ? (array) $notification->toArray($notifiable) : [];
        $title = trim((string) ($data['title'] ?? 'You have a new notification'));
        $body = Str::limit(trim((string) ($data['body'] ?? '')), 240);
        $url = isset($data['url']) && is_string($data['url']) && $data['url'] !== '' ? url($data['url']) : null;

        return ['title' => $title, 'body' => $body, 'url' => $url, 'event' => isset($data['event']) ? (string) $data['event'] : null];
    }
}
