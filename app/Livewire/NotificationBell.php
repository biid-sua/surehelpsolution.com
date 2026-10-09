<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * In-app notifications (NTF-02). Polls every 30 s until real-time push (Reverb) arrives.
 * Only ever reads the signed-in user's own notifications.
 *
 * A poll can arrive after the session ended (signed out in another tab, idle timeout): then the
 * page is sent to sign-in instead of failing.
 */
class NotificationBell extends Component
{
    public function boot(): void
    {
        if (! auth()->check()) {
            $this->skipRender();
            $this->redirectRoute('login');
        }
    }

    public function open(string $id): mixed
    {
        if (! auth()->check()) {
            return null;
        }
        $notification = $this->find($id);
        $notification->markAsRead();

        return $this->redirect($this->safeUrl($notification->data['url'] ?? null));
    }

    public function markAllRead(): void
    {
        auth()->user()?->unreadNotifications()->update(['read_at' => now()]);
    }

    private function find(string $id): DatabaseNotification
    {
        /** @var DatabaseNotification */
        return auth()->user()->notifications()->whereKey($id)->firstOrFail();
    }

    /**
     * Only same-site relative paths stored by the server are followed.
     */
    private function safeUrl(?string $url): string
    {
        return is_string($url) && Str::startsWith($url, '/') && ! Str::startsWith($url, '//')
            ? $url
            : auth()->user()->homeUrl();
    }

    public function render(): View
    {
        $user = auth()->user();

        return view('livewire.notification-bell', [
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->latest()->limit(8)->get(),
        ]);
    }
}
