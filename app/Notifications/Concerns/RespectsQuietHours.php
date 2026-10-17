<?php

namespace App\Notifications\Concerns;

use App\Models\User;

/**
 * During someone's quiet hours, their email, texts and push wait until quiet hours end (NTF-07, D63).
 * In-app notifications still arrive straight away, silently. Urgent notifications don't use this.
 */
trait RespectsQuietHours
{
    /** @return array<string, \DateTimeInterface> */
    public function withDelay(object $notifiable): array
    {
        $until = $notifiable instanceof User ? $notifiable->quietUntil() : null;

        return $until ? ['mail' => $until, 'sms' => $until, 'push' => $until] : [];
    }
}
