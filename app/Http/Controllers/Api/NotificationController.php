<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use App\Services\FcmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function __construct(private readonly FcmService $fcmService) {}

    public function storeDeviceToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'nullable|string|max:50',
            'device_name' => 'nullable|string|max:100',
        ]);

        $record = FcmToken::updateOrCreate(
            ['token' => $request->token],
            [
                'user_id' => Auth::id(),
                'platform' => $request->platform,
                'device_name' => $request->device_name,
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Device token registered successfully.',
            'data' => $record,
        ]);
    }

    public function sendTestNotification(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:500',
            'token' => 'nullable|string',
        ]);

        // Without an explicit token, test against the admin's own devices only.
        $tokens = $request->filled('token')
            ? [$request->token]
            : FcmToken::where('user_id', Auth::id())->pluck('token')->all();

        $result = $this->fcmService->sendNotification(
            $tokens,
            [
                'title' => $request->title,
                'body' => $request->body,
            ],
            $request->get('data', [])
        );

        return response()->json([
            'success' => true,
            'message' => 'Notification dispatched to FCM.',
            'fcm_response' => $result,
        ]);
    }
}
