<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Telephony\TelephonyProvider;
use App\Support\NotificationChannels;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Notifications by text (D63), from SureHelp's own number to the mobile number the person
 * verified. Short: the title, a line of detail and a link. Nothing is sent without a verified number.
 */
class SmsChannel
{
    public function __construct(private readonly TelephonyProvider $provider) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable instanceof User ? $notifiable->routeNotificationForSms() : null;
        if (! $to || ! NotificationChannels::enabled('sms')) {
            return;
        }

        $this->provider->sendSms((string) config('telephony.notify_from'), $to, self::text($notifiable, $notification), route('webhooks.sms.status'));
    }

    /** "SureHelp: Missed call from Maria Lopez. Maria called… https://…" (at most 320 characters). */
    public static function text(object $notifiable, Notification $notification): string
    {
        $message = PushChannel::message($notifiable, $notification);
        $link = $message['url'] ? ' '.$message['url'] : '';
        $sentence = fn (string $text) => preg_match('/[.!?…]$/u', $text) ? $text : $text.'.';
        $text = 'SureHelp: '.$sentence($message['title']).($message['body'] ? ' '.$sentence($message['body']) : '');

        return Str::limit($text, 320 - strlen($link), '…').$link;
    }
}
