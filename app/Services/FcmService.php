<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private const FCM_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';

    public function sendNotification(array $tokens, array $notification, array $data = []): array
    {
        // Only ever send to the recipient's own devices. There is deliberately no
        // fallback list: it would deliver one client's call details to other devices.
        $tokens = array_values(array_filter(array_unique($tokens)));

        if (empty($tokens)) {
            Log::info('FCM send skipped: recipient has no registered devices.');

            return [
                'skipped' => true,
                'message' => 'No device tokens available.',
            ];
        }

        $payload = [
            'registration_ids' => $tokens,
            'notification' => array_merge([
                'title' => 'New Notification',
                'body' => 'You have a new update.',
                'sound' => config('fcm.default_sound'),
            ], $notification),
            'data' => $data,
            'android' => [
                'notification' => [
                    'channel_id' => config('fcm.android_channel_id'),
                ],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'key='.config('fcm.server_key'),
            'Content-Type' => 'application/json',
        ])->post(self::FCM_ENDPOINT, $payload);

        // Log delivery outcome only — never the message content or device tokens.
        Log::info('FCM response', [
            'status' => $response->status(),
            'recipients' => count($tokens),
            'success' => $response->json('success'),
            'failure' => $response->json('failure'),
        ]);

        return [
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    }
}
