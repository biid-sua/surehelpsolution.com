<?php

namespace App\Livewire\Admin\Usage;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Platform usage by business for a calendar month (spec §3.5 "Usage"): calls answered against the
 * plan's allowance, bookings, new customers and inbox traffic. Counts follow Billing\Usage (spam
 * calls never count). Busiest businesses first.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Usage')]
class Index extends Component
{
    use PlatformAdminOnly;
    use WithPagination;

    /** "Y-m"; empty = this month. */
    #[Url(except: '')]
    public string $month = '';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC [start, end) of the month */
    private function bounds(): array
    {
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month)
            ? CarbonImmutable::parse($this->month.'-01', (string) config('app.timezone'))
            : CarbonImmutable::now((string) config('app.timezone'))->startOfMonth();

        return [$start->utc(), $start->addMonthNoOverflow()->utc()];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<int>  $ids
     * @return array<int, int> organization id => count
     */
    private function countBy(string $model, array $ids, \Closure $scope): array
    {
        $query = $model::withoutGlobalScopes()->whereIn('organization_id', $ids);
        $scope($query);

        return $query->toBase()->select('organization_id', DB::raw('COUNT(*) as n'))->groupBy('organization_id')->pluck('n', 'organization_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    public function render(): View
    {
        [$from, $until] = $this->bounds();
        $window = fn (Builder $q, string $column = 'created_at') => $q->where($column, '>=', $from)->where($column, '<', $until);
        $answered = fn (Builder $q) => $window($q)->where('status', '!=', 'spam');

        $organizations = Organization::query()
            ->withCount(['callLogs as calls' => $answered])
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes(trim($this->search), '%_\\').'%'))
            ->orderByDesc('calls')->orderBy('name')
            ->paginate(25);
        $ids = $organizations->getCollection()->pluck('id')->all();

        $plans = Subscription::withoutGlobalScopes()->whereIn('organization_id', $ids)->current()->with('plan:id,name,limits')
            ->latest('id')->get()->unique('organization_id')->keyBy('organization_id');

        $months = [];
        for ($m = CarbonImmutable::now((string) config('app.timezone'))->startOfMonth(), $i = 0; $i < 13; $m = $m->subMonthNoOverflow(), $i++) {
            $months[$m->format('Y-m')] = $m->format('F Y');
        }

        return view('livewire.admin.usage.index', [
            'organizations' => $organizations,
            'plans' => $plans,
            'appointments' => $this->countBy(Appointment::class, $ids, fn ($q) => $window($q)->where('status', '!=', 'cancelled')),
            'customers' => $this->countBy(Customer::class, $ids, fn ($q) => $window($q)),
            'messagesIn' => $this->countBy(Message::class, $ids, fn ($q) => $window($q)->where('direction', 'in')),
            'messagesOut' => $this->countBy(Message::class, $ids, fn ($q) => $window($q)->where('direction', 'out')->where('status', 'sent')),
            'totals' => [
                'calls' => $answered(CallLog::withoutGlobalScopes())->count(),
                'appointments' => $window(Appointment::withoutGlobalScopes())->where('status', '!=', 'cancelled')->count(),
                'customers' => $window(Customer::withoutGlobalScopes())->count(),
                'messages' => $window(Message::withoutGlobalScopes())->count(),
            ],
            'months' => $months,
            'monthKey' => $from->setTimezone((string) config('app.timezone'))->format('Y-m'),
        ]);
    }
}
