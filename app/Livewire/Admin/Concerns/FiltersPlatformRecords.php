<?php

namespace App\Livewire\Admin\Concerns;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Shared filters for the admin console's platform-wide lists (calls, appointments, customers):
 * a business, a date range (platform timezone) and free text. Lists are always paginated.
 */
trait FiltersPlatformRecords
{
    #[Url(except: '')]
    public string $search = '';

    /** Organization ULID. */
    #[Url(as: 'business', except: '')]
    public string $business = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'business', 'from', 'to');
        if (property_exists($this, 'status')) {
            $this->reset('status');
        }
        $this->resetPage();
    }

    protected function organizationId(): ?int
    {
        return $this->business !== '' ? Organization::query()->where('ulid', $this->business)->value('id') : null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function applyCommonFilters(Builder $query, string $dateColumn): Builder
    {
        $date = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? CarbonImmutable::parse($d, (string) config('app.timezone')) : null;

        return $query
            ->when($this->business !== '', fn (Builder $q) => $q->where($query->getModel()->getTable().'.organization_id', $this->organizationId() ?? 0))
            ->when($date($this->from), fn (Builder $q, CarbonImmutable $d) => $q->where($dateColumn, '>=', $d->startOfDay()->utc()))
            ->when($date($this->to), fn (Builder $q, CarbonImmutable $d) => $q->where($dateColumn, '<=', $d->endOfDay()->utc()));
    }

    protected function likeTerm(): string
    {
        return '%'.addcslashes(trim($this->search), '%_\\').'%';
    }

    /** @return Collection<int, Organization> */
    protected function businessOptions(): Collection
    {
        return Organization::query()->orderBy('name')->get(['id', 'ulid', 'name']);
    }
}
