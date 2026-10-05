<?php

namespace App\Notifications;

use App\Models\DataExport;
use App\Models\User;
use App\Services\Privacy\DataExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The business's data export is ready to download (CMP-07). The link needs the person to be
 * signed in, so the email itself never carries the data.
 */
class DataExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly DataExport $export) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'event' => 'data_export.ready',
            'title' => 'Your data export is ready',
            'body' => 'Download it within '.DataExporter::KEEP_DAYS.' days.',
            'url' => route('app.settings.privacy', absolute: false),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your data export is ready')
            ->greeting('Hi '.Str::before(trim($notifiable->name).' ', ' ').',')
            ->line('The export of '.($this->export->organization->name ?? 'your business').'\'s data is ready. Sign in to download it.')
            ->action('Download', route('app.settings.privacy'))
            ->line('The file is deleted after '.DataExporter::KEEP_DAYS.' days.');
    }

    public function databaseType(object $notifiable): string
    {
        return 'data_export.ready';
    }
}
