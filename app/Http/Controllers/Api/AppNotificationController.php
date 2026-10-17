<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\Mobile\AppLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * The in-app notification centre for the mobile app (API-2): the same items as the web bell, read
 * state, and where each one leads. Only ever the signed-in person's own notifications.
 */
class AppNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $request->user()->notifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest()->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 20));

        return ApiResponse::success(
            ['notifications' => $page->getCollection()->map(fn (DatabaseNotification $n) => self::present($n))->values()],
            meta: [
                'unread' => $request->user()->unreadNotifications()->count(),
                'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ],
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        /** @var DatabaseNotification $notification */
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return ApiResponse::success(['notification' => self::present($notification), 'unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['unread' => 0], 'All notifications marked as read.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->delete();

        return ApiResponse::success(['unread' => $request->user()->unreadNotifications()->count()], 'Notification removed.');
    }

    /** @return array<string, mixed> */
    public static function present(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;
        $url = isset($data['url']) && is_string($data['url']) && Str::startsWith($data['url'], '/') && ! Str::startsWith($data['url'], '//') ? $data['url'] : null;

        return [
            'id' => $notification->id,
            'type' => Str::snake(class_basename($notification->type)),
            'event' => isset($data['event']) ? (string) $data['event'] : null,
            'title' => (string) ($data['title'] ?? 'Notification'),
            'body' => isset($data['body']) ? (string) $data['body'] : null,
            'url' => $url ? url($url) : null,
            'target' => AppLink::resolve($url),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
