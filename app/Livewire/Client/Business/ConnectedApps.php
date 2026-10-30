<?php

namespace App\Livewire\Client\Business;

use App\Jobs\PullQuickBooks;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\AppConnection;
use App\Models\AppLink;
use App\Services\Apps\QuickBooks\QuickBooks;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Connected apps (D110): the business's own software. QuickBooks Online first: connect
 * the company, choose what goes each way, and see what was sent and received.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Connected apps')]
class ConnectedApps extends Component
{
    use ScopedToOrganization;

    /** @var array<string, bool> */
    public array $settings = [];

    public function mount(): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $connection = $this->connection();
        foreach (array_keys(AppConnection::DEFAULTS[AppConnection::QUICKBOOKS]) as $key) {
            $this->settings[$key] = $connection ? $connection->wants($key) : AppConnection::DEFAULTS[AppConnection::QUICKBOOKS][$key];
        }
    }

    public function save(Audit $audit): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $connection = $this->connection();
        abort_unless($connection !== null, 404);
        $new = [];
        foreach (array_keys(AppConnection::DEFAULTS[AppConnection::QUICKBOOKS]) as $key) {
            $new[$key] = (bool) ($this->settings[$key] ?? false);
        }
        $old = array_map(fn ($key) => $connection->wants($key), array_combine(array_keys($new), array_keys($new)));
        $connection->forceFill(['settings' => $new])->save();
        if ($old !== $new) {
            $audit->record('apps.quickbooks_settings_updated', $connection, old: $old, new: $new);
        }
        $this->dispatch('toast', type: 'success', message: 'Saved.');
    }

    public function syncNow(): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $connection = $this->connection();
        abort_unless($connection !== null && $connection->isActive(), 404);
        if (RateLimiter::tooManyAttempts('quickbooks-sync:'.$connection->id, 3)) {
            $this->dispatch('toast', type: 'warning', message: 'Already fetching. Please wait a few minutes.');

            return;
        }
        RateLimiter::hit('quickbooks-sync:'.$connection->id, 600);
        PullQuickBooks::dispatchAfterResponse($connection->id);
        $this->dispatch('toast', type: 'success', message: 'Fetching changes from QuickBooks.');
    }

    public function disconnect(Audit $audit, QuickBooks $quickBooks): void
    {
        $this->authorize('integrations.manage', $this->organization());
        $connection = $this->connection();
        if ($connection) {
            $audit->record('apps.quickbooks_disconnected', $connection, old: ['company' => $connection->account_name]);
            $quickBooks->disconnect($connection);
        }
        session()->flash('status', 'QuickBooks disconnected. Customers already brought in stay in SureHelp.');
        $this->redirectRoute('app.business.apps', navigate: true);
    }

    private function connection(): ?AppConnection
    {
        return AppConnection::query()->forOrganization($this->organization())->where('provider', AppConnection::QUICKBOOKS)->first();
    }

    public function render(QuickBooks $quickBooks): View
    {
        $connection = $this->connection();
        $count = fn (string $type) => $connection ? AppLink::query()->forOrganization($this->organization())->where('app_connection_id', $connection->id)->where('local_type', $type)->count() : 0;

        return view('livewire.client.business.connected-apps', [
            'connection' => $connection,
            'configured' => $quickBooks->isConfigured(),
            'customers' => $count('customer'),
            'payments' => $count('payment'),
            'entries' => $connection ? $connection->entries()->latest('id')->limit(15)->get() : collect(),
            'timezone' => $this->organization()->timezoneOrDefault(),
        ]);
    }
}
