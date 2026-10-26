<?php

namespace App\Livewire\Admin;

use App\Jobs\RunDemoData;
use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Organization;
use App\Services\Demo\DemoData as Demo;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Admin › Demo data (D94): load five sample businesses with four months of history to learn the
 * system, and remove them again. Only platform administrators with settings access can do either.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Demo data')]
class DemoData extends Component
{
    use PlatformAdminOnly;

    public string $confirm = '';

    public function mount(): void
    {
        $this->authorize('settings.view');
    }

    public function load(Demo $demo): void
    {
        $this->authorize('settings.manage');
        if (! $demo->begin('load', auth()->user())) {
            $this->dispatch('toast', type: 'warning', message: 'Demo data is already loaded or being changed.');

            return;
        }
        RunDemoData::dispatchAfterResponse('load', auth()->id());
        $this->dispatch('toast', type: 'success', message: 'Loading the demo data. This takes a minute or two; this page updates when it\'s ready.');
    }

    public function remove(Demo $demo): void
    {
        $this->authorize('settings.manage');
        $this->validate(['confirm' => ['required', 'in:REMOVE']], ['confirm.in' => 'Type REMOVE in capitals to confirm.', 'confirm.required' => 'Type REMOVE in capitals to confirm.']);
        if (! $demo->begin('remove', auth()->user())) {
            $this->dispatch('toast', type: 'warning', message: 'There\'s no demo data to remove right now.');

            return;
        }
        $this->reset('confirm');
        RunDemoData::dispatchAfterResponse('remove', auth()->id());
        $this->dispatch('toast', type: 'success', message: 'Removing the demo data. This page updates when it\'s done.');
    }

    public function recover(Demo $demo): void
    {
        $this->authorize('settings.manage');
        $status = $demo->recover(auth()->user());
        $this->dispatch('toast', type: 'info', message: $status === 'loaded' ? 'Demo data is in the system: you can use it or remove it.' : 'No demo data is in the system.');
    }

    public function render(Demo $demo): View
    {
        $state = $demo->state();

        return view('livewire.admin.demo-data', [
            'state' => $state,
            'busy' => in_array($state['status'], ['loading', 'removing'], true) && ! $state['stuck'],
            'businesses' => Organization::withoutGlobalScopes()->where('is_demo', true)->with('owner:id,name,email,is_active')->orderBy('id')->get(),
            'canManage' => auth()->user()->hasPermissionIn('settings.manage'),
            'canImpersonate' => auth()->user()->hasPermissionIn('users.impersonate'),
        ]);
    }
}
