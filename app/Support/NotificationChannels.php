<?php

namespace App\Support;

use App\Services\FcmService;
use App\Services\Telephony\Texting;

/**
 * Which notification channels work on this installation (D63). In-app and email always do;
 * texts need a SureHelp number to send from (and texting switched on); push needs Firebase.
 */
final class NotificationChannels
{
    public static function enabled(string $channel): bool
    {
        return match ($channel) {
            'database', 'mail' => true,
            'sms' => filled(config('telephony.notify_from')) && app(Texting::class)->enabled(),
            'push' => app(FcmService::class)->isConfigured(),
            default => false,
        };
    }

    /** @return array<string, array{label: string, enabled: bool}> every channel, with whether it works here */
    public static function all(): array
    {
        $channels = [];
        foreach (config('notifications.channels') as $key => $channel) {
            $channels[$key] = ['label' => (string) $channel['label'], 'enabled' => self::enabled($key)];
        }

        return $channels;
    }

    /** @return list<string> */
    public static function enabledKeys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $c) => $c['enabled']));
    }
}
