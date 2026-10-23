<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Mail\SystemTestMail;
use App\Services\System\LaunchReadiness;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Go-live readiness (D79): what's ready, what to fix, which features are still off, and the
 * addresses to register with each provider. The same checks as `php artisan launch:check`.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('System status')]
class SystemStatus extends Component
{
    use PlatformAdminOnly;

    public string $testEmail = '';

    public function mount(): void
    {
        $this->authorize('settings.view');
        $this->testEmail = (string) auth()->user()->email;
    }

    public function sendTestEmail(Audit $audit): void
    {
        $this->authorize('settings.manage');
        $this->validate(['testEmail' => ['required', 'email']]);
        try {
            Mail::to($this->testEmail)->send(new SystemTestMail);
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', type: 'error', message: 'Sending failed: '.str($e->getMessage())->limit(140));

            return;
        }
        $audit->record('system.test_email', auth()->user(), label: $this->testEmail);
        $this->dispatch('toast', type: 'success', message: 'Test email sent. Check the inbox and the spam folder.');
    }

    public function render(LaunchReadiness $readiness): View
    {
        $checks = $readiness->checks();

        return view('livewire.admin.system-status', [
            'groups' => collect($checks)->groupBy('group'),
            'summary' => $readiness->summary($checks),
            'urls' => $readiness->urls(),
            'canManage' => auth()->user()->hasPermissionIn('settings.manage'),
        ]);
    }
}
