<?php

namespace App\Services\Rules;

use App\Enums\BusinessRuleType;
use App\Enums\EscalationPriority;
use App\Enums\EscalationType;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Applies a business's rules (spec §23). Request-scoped and memoised.
 *
 * Booking rules bind agents and automated booking (and shape the free times everyone is offered);
 * the business itself may still book any time from its portal.
 */
class BusinessRules
{
    /** @var array<int, Collection<int, BusinessRule>> */
    private array $cache = [];

    /**
     * @return Collection<int, BusinessRule>
     */
    public function active(Organization $organization): Collection
    {
        return $this->cache[$organization->getKey()] ??= BusinessRule::query()->forOrganization($organization)
            ->where('is_active', true)->orderBy('id')->get();
    }

    /**
     * @return Collection<int, BusinessRule>
     */
    public function ofType(Organization $organization, BusinessRuleType $type): Collection
    {
        return $this->active($organization)->filter(fn (BusinessRule $r) => $r->type === $type)->values();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * Rule breaches for a proposed booking, keyed by field (empty when fine).
     *
     * @param  array{address?: ?string}  $details
     * @return array<string, string>
     */
    public function bookingViolations(Organization $organization, CarbonImmutable $start, ?BusinessService $service, ?Customer $customer, array $details = []): array
    {
        $violations = [];
        $local = $start->setTimezone($organization->timezoneOrDefault());

        if ($cutoff = $this->cutoffFor($organization, $service?->id)) {
            if ($local->format('H:i') >= $cutoff) {
                $violations['starts_at'] = 'The business doesn\'t take '.($service->name ?? 'bookings').' starting at or after '.CarbonImmutable::createFromFormat('H:i', $cutoff)->format('g:i A').'.';
            }
        }

        [$minNotice, $maxDays] = $this->window($organization);
        if ($minNotice !== null && $start->lessThan(now()->addMinutes($minNotice))) {
            $violations['starts_at'] ??= 'The business needs at least '.$minNotice.' minutes\' notice.';
        }
        if ($maxDays !== null && $local->startOfDay()->greaterThan(now($organization->timezoneOrDefault())->startOfDay()->addDays($maxDays))) {
            $violations['starts_at'] ??= 'The business only books up to '.$maxDays.' days ahead.';
        }

        $address = trim((string) ($details['address'] ?? '')) ?: $customer?->singleLineAddress();

        foreach ($this->ofType($organization, BusinessRuleType::RequireDetail) as $rule) {
            $field = $rule->config['field'] ?? '';
            $missing = match ($field) {
                'address' => blank($address),
                'phone' => blank($customer?->phone),
                'email' => blank($customer?->email),
                default => false,
            };
            if ($missing) {
                $violations[$field === 'address' ? 'address' : 'customer_id'] ??= 'Get '.(BusinessRule::DETAILS[$field] ?? 'this detail').' before booking (business rule).';
            }
        }

        $areas = $this->ofType($organization, BusinessRuleType::ServiceArea);
        if ($areas->isNotEmpty() && filled($address)) {
            $allowed = $areas->flatMap(fn (BusinessRule $r) => (array) ($r->config['postal_codes'] ?? []))->map(fn ($z) => (string) $z)->all();
            $zip = self::postalCode((string) $address) ?? $customer?->postal_code;

            if ($zip === null) {
                $violations['address'] ??= 'Include the ZIP code so we can check the service area.';
            } elseif (! in_array(substr($zip, 0, 5), $allowed, true)) {
                $violations['address'] ??= "ZIP {$zip} is outside the business's service area.";
            }
        }

        return $violations;
    }

    /** Earliest cutoff ("HH:MM") that applies to this service, or null. */
    public function cutoffFor(Organization $organization, ?int $serviceId): ?string
    {
        return $this->ofType($organization, BusinessRuleType::BookingCutoff)
            ->filter(fn (BusinessRule $r) => empty($r->config['service_id']) || (int) $r->config['service_id'] === $serviceId)
            ->map(fn (BusinessRule $r) => (string) ($r->config['time'] ?? ''))
            ->filter(fn (string $t) => preg_match('/^\d{2}:\d{2}$/', $t) === 1)
            ->sort()
            ->first();
    }

    /**
     * @return array{0: ?int, 1: ?int} [minimum notice minutes, maximum days ahead]
     */
    public function window(Organization $organization): array
    {
        $rules = $this->ofType($organization, BusinessRuleType::BookingWindow);
        $notice = $rules->map(fn (BusinessRule $r) => $r->config['min_notice_minutes'] ?? null)->filter()->max();
        $days = $rules->map(fn (BusinessRule $r) => $r->config['max_days_ahead'] ?? null)->filter()->min();

        return [$notice !== null ? (int) $notice : null, $days !== null ? (int) $days : null];
    }

    /**
     * Escalation a call with this reason should raise, if a rule says so.
     *
     * @return array{type: EscalationType, priority: ?EscalationPriority}|null
     */
    public function escalationFor(Organization $organization, ?string $reason): ?array
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        $rule = $this->ofType($organization, BusinessRuleType::AutoEscalate)
            ->first(fn (BusinessRule $r) => in_array($reason, (array) ($r->config['reasons'] ?? []), true));

        return $rule ? [
            'type' => EscalationType::tryFrom((string) ($rule->config['escalation_type'] ?? '')) ?? EscalationType::UrgentIssue,
            'priority' => EscalationPriority::tryFrom((string) ($rule->config['priority'] ?? '')),
        ] : null;
    }

    /**
     * Every active rule as a sentence, instructions first: the briefing agents read on each call.
     *
     * @return list<string>
     */
    public function briefing(Organization $organization): array
    {
        $names = BusinessService::query()->forOrganization($organization)->withTrashed()->pluck('name', 'id')->all();

        return $this->active($organization)
            ->sortBy(fn (BusinessRule $r) => $r->type === BusinessRuleType::Instruction ? 0 : 1)
            ->map(fn (BusinessRule $r) => $r->sentence($names))
            ->filter()
            ->values()
            ->all();
    }

    public static function postalCode(string $address): ?string
    {
        return preg_match('/\b(\d{5})(?:-\d{4})?\b(?!.*\b\d{5}\b)/', $address, $m) ? $m[1] : null;
    }
}
