<?php

namespace App\Livewire\Client\Business;

use App\Exceptions\AppAuthorizationLost;
use App\Jobs\PullHousecallPro;
use App\Jobs\PullJobber;
use App\Jobs\PullQuickBooks;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\AppConnection;
use App\Models\AppLink;
use App\Models\AppSyncEntry;
use App\Services\Apps\HousecallPro\HousecallPro;
use App\Services\Apps\Jobber\Jobber;
use App\Services\Apps\QuickBooks\QuickBooks;
use App\Services\Billing\FeatureAccess;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Business › Connected apps: the business's own software, QuickBooks Online (D110), Jobber (D111) and
 * Housecall Pro (D112). Connect, choose what goes each way, and see what was sent and received.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Connected apps')]
class ConnectedApps extends Component
{
    use ScopedToOrganization;

    /** Each app's feature key is its provider name. */
    public const APPS = [AppConnection::QUICKBOOKS => 'QuickBooks', AppConnection::JOBBER => 'Jobber', AppConnection::HOUSECALL_PRO => 'Housecall Pro'];

    /** @var array<string, array<string, bool>> by app */
    public array $settings = [];

    /** The Housecall Pro API key being pasted; never shown again once saved. */
    public string $housecallKey = '';

    public function mount(): void
    {
        $this->authorize('integrations.manage', $this->organization());
        abort_if($this->available() === [], 404);
        foreach ($this->available() as $app) {
            $connection = $this->connection($app);
            foreach (AppConnection::DEFAULTS[$app] as $key => $default) {
                $this->settings[$app][$key] = $connection ? $connection->wants($key) : $default;
            }
        }
    }

    /** Housecall Pro has no sign-in page for apps: the owner pastes an API key made in Housecall Pro. */
    public function connectHousecallPro(Audit $audit, HousecallPro $housecallPro): void
    {
        $this->authorize('integrations.manage', $this->organization());
        abort_unless(in_array(AppConnection::HOUSECALL_PRO, $this->available(), true), 404);
        $this->validate(['housecallKey' => ['required', 'string', 'min:10', 'max:200']], [], ['housecallKey' => 'API key']);
        try {
            $connection = $housecallPro->connect($this->organization(), trim($this->housecallKey), auth()->user());
        } catch (AppAuthorizationLost) {
            $this->addError('housecallKey', 'Housecall Pro didn\'t accept that key. Copy it again from Housecall Pro.');

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('housecallKey', 'We couldn\'t reach Housecall Pro. Please try again in a minute.');

            return;
        }
        $this->reset('housecallKey');
        $audit->record('apps.housecall_pro_connected', $connection, new: ['account' => $connection->account_name]);
        PullHousecallPro::dispatchAfterResponse($connection->id);
        foreach (AppConnection::DEFAULTS[AppConnection::HOUSECALL_PRO] as $key => $default) {
            $this->settings[AppConnection::HOUSECALL_PRO][$key] = $connection->wants($key);
        }
        $this->dispatch('toast', type: 'success', message: 'Housecall Pro connected. Your customers are coming in now.');
    }

    public function save(string $app, Audit $audit): void
    {
        $connection = $this->authorizedConnection($app);
        $new = [];
        foreach (array_keys(AppConnection::DEFAULTS[$app]) as $key) {
            $new[$key] = (bool) ($this->settings[$app][$key] ?? false);
        }
        $old = array_map(fn ($key) => $connection->wants($key), array_combine(array_keys($new), array_keys($new)));
        $connection->forceFill(['settings' => $new])->save();
        if ($old !== $new) {
            $audit->record('apps.'.$app.'_settings_updated', $connection, old: $old, new: $new);
        }
        $this->dispatch('toast', type: 'success', message: 'Saved.');
    }

    public function syncNow(string $app): void
    {
        $connection = $this->authorizedConnection($app);
        abort_unless($connection->isActive(), 404);
        if (RateLimiter::tooManyAttempts('app-sync:'.$connection->id, 3)) {
            $this->dispatch('toast', type: 'warning', message: 'Already fetching. Please wait a few minutes.');

            return;
        }
        RateLimiter::hit('app-sync:'.$connection->id, 600);
        match ($app) {
            AppConnection::JOBBER => PullJobber::dispatchAfterResponse($connection->id),
            AppConnection::HOUSECALL_PRO => PullHousecallPro::dispatchAfterResponse($connection->id),
            default => PullQuickBooks::dispatchAfterResponse($connection->id),
        };
        $this->dispatch('toast', type: 'success', message: 'Fetching changes from '.self::APPS[$app].'.');
    }

    public function disconnect(string $app, Audit $audit): void
    {
        $connection = $this->authorizedConnection($app);
        $audit->record('apps.'.$app.'_disconnected', $connection, old: ['account' => $connection->account_name]);
        match ($app) {
            AppConnection::JOBBER => app(Jobber::class)->disconnect($connection),
            AppConnection::HOUSECALL_PRO => app(HousecallPro::class)->disconnect($connection),
            default => app(QuickBooks::class)->disconnect($connection),
        };
        session()->flash('status', self::APPS[$app].' disconnected. Customers already brought in stay in SureHelp.');
        $this->redirectRoute('app.business.apps', navigate: true);
    }

    /** @return list<string> apps this business can use */
    private function available(): array
    {
        $features = app(FeatureAccess::class);

        return array_values(array_filter(array_keys(self::APPS), fn (string $app) => $features->allows($this->organization(), $app)));
    }

    private function authorizedConnection(string $app): AppConnection
    {
        $this->authorize('integrations.manage', $this->organization());
        abort_unless(in_array($app, $this->available(), true), 404);
        $connection = $this->connection($app);
        abort_unless($connection !== null, 404);

        return $connection;
    }

    private function connection(string $app): ?AppConnection
    {
        return AppConnection::query()->forOrganization($this->organization())->where('provider', $app)->first();
    }

    public function render(): View
    {
        $apps = [];
        foreach ($this->available() as $app) {
            $connection = $this->connection($app);
            $count = fn (string $type) => $connection ? AppLink::query()->forOrganization($this->organization())->where('app_connection_id', $connection->id)->where('local_type', $type)->count() : 0;
            $apps[$app] = [
                'connection' => $connection,
                'configured' => match ($app) {
                    AppConnection::JOBBER => app(Jobber::class)->isConfigured(),
                    AppConnection::HOUSECALL_PRO => true,   // each business brings its own key
                    default => app(QuickBooks::class)->isConfigured(),
                },
                'customers' => $count('customer'),
                'payments' => $count('payment'),
                'bookings' => $count('appointment'),
            ];
        }
        $connections = collect($apps)->pluck('connection')->filter();

        return view('livewire.client.business.connected-apps', [
            'apps' => $apps,
            'names' => self::APPS,
            'providers' => $connections->pluck('provider', 'id')->all(),
            'entries' => AppSyncEntry::query()->forOrganization($this->organization())->whereIn('app_connection_id', $connections->pluck('id'))->latest('id')->limit(20)->get(),
            'timezone' => $this->organization()->timezoneOrDefault(),
        ]);
    }
}
