<?php

namespace App\Services\Calls;

use App\Enums\OutcomeCategory;
use App\Models\CallOutcome;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * The effective call outcomes for a business: platform defaults, with the business's
 * overrides (label, active) applied, plus its own outcomes (spec §14).
 *
 * Request-scoped and memoised: call lists ask about every row.
 */
class CallOutcomes
{
    /** @var array<int|string, Collection<string, array{key: string, label: string, category: OutcomeCategory, is_active: bool, sort_order: int, custom: bool, overridden: bool}>> */
    private array $cache = [];

    /** @var Collection<int, CallOutcome>|null */
    private ?Collection $allRows = null;

    /**
     * @return Collection<string, array{key: string, label: string, category: OutcomeCategory, is_active: bool, sort_order: int, custom: bool, overridden: bool}>
     */
    public function effective(Organization|int|null $organization): Collection
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $this->cache[$id ?? 'platform'] ??= $this->build($id);
    }

    /**
     * Outcomes agents may choose for this business, in display order.
     *
     * @return Collection<string, array{key: string, label: string, category: OutcomeCategory, is_active: bool, sort_order: int, custom: bool, overridden: bool}>
     */
    public function active(Organization|int|null $organization): Collection
    {
        return $this->effective($organization)->where('is_active', true);
    }

    public function isAllowed(Organization|int|null $organization, ?string $key): bool
    {
        return $key !== null && (bool) ($this->active($organization)->get($key)['is_active'] ?? false);
    }

    /**
     * Category of an outcome key for a business; null for unknown legacy values.
     */
    public function category(Organization|int|null $organization, ?string $key): ?OutcomeCategory
    {
        return $key === null ? null : ($this->effective($organization)->get($key)['category'] ?? null);
    }

    public function label(Organization|int|null $organization, ?string $key): ?string
    {
        return $key === null ? null : ($this->effective($organization)->get($key)['label'] ?? null);
    }

    /**
     * Keys in the given categories. For a business: its effective outcomes (active or not, so
     * history still counts). For the platform (null): every key in those categories anywhere.
     *
     * @return list<string>
     */
    public function keys(Organization|int|null $organization, OutcomeCategory ...$categories): array
    {
        $categories = array_map(fn (OutcomeCategory $c) => $c->value, $categories);

        if ($organization === null) {
            return $this->rows()
                ->filter(fn (CallOutcome $o) => in_array($o->category->value, $categories, true))
                ->pluck('key')->unique()->values()->all();
        }

        return $this->effective($organization)
            ->filter(fn (array $o) => in_array($o['category']->value, $categories, true))
            ->keys()->values()->all();
    }

    /**
     * The pick-list agents see for a business: active outcomes as plain arrays (API and forms).
     *
     * @return list<array{key: string, label: string, category: string}>
     */
    public function menu(Organization|int|null $organization): array
    {
        return $this->active($organization)
            ->map(fn (array $o) => ['key' => $o['key'], 'label' => $o['label'], 'category' => $o['category']->value])
            ->values()->all();
    }

    public function forget(): void
    {
        $this->cache = [];
        $this->allRows = null;
    }

    /**
     * @return Collection<string, array{key: string, label: string, category: OutcomeCategory, is_active: bool, sort_order: int, custom: bool, overridden: bool}>
     */
    private function build(?int $organizationId): Collection
    {
        $defaults = $this->rows()->whereNull('organization_id');
        $own = $organizationId ? $this->rows()->where('organization_id', $organizationId)->keyBy('key') : collect();

        $outcomes = collect();
        foreach ($defaults as $default) {
            $override = $own->get($default->key);
            $outcomes->put($default->key, [
                'key' => $default->key,
                'label' => $override->label ?? $default->label,
                'category' => $default->category, // a default's meaning can't be changed, only its wording
                'is_active' => $default->is_active && ($override->is_active ?? true),
                'sort_order' => $default->sort_order,
                'custom' => false,
                'overridden' => $override !== null,
            ]);
        }

        foreach ($own as $key => $row) {
            if (! $outcomes->has($key)) {
                $outcomes->put($key, [
                    'key' => $key,
                    'label' => $row->label,
                    'category' => $row->category,
                    'is_active' => $row->is_active,
                    'sort_order' => 1000 + $row->sort_order,
                    'custom' => true,
                    'overridden' => false,
                ]);
            }
        }

        return $outcomes->sortBy('sort_order');
    }

    /**
     * @return Collection<int, CallOutcome>
     */
    private function rows(): Collection
    {
        return $this->allRows ??= CallOutcome::query()->orderBy('sort_order')->orderBy('id')->get();
    }
}
