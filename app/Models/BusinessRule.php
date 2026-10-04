<?php

namespace App\Models;

use App\Enums\BusinessRuleType;
use App\Enums\EscalationType;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A rule the business set for how calls and bookings are handled (spec §23).
 *
 * Config per type:
 *  - instruction:     {text}
 *  - booking_cutoff:  {time: "HH:MM", service_id: ?int}           no bookings starting at or after this local time
 *  - booking_window:  {min_notice_minutes: ?int, max_days_ahead: ?int}
 *  - service_area:    {postal_codes: list<string>}
 *  - require_detail:  {field: "address"|"phone"|"email"}
 *  - auto_escalate:   {reasons: list<string>, escalation_type: string, priority: ?string}
 *
 * @property BusinessRuleType $type
 * @property array<string, mixed> $config
 */
class BusinessRule extends Model
{
    use BelongsToOrganization;

    public const DETAILS = ['address' => 'the visit address', 'phone' => 'a phone number', 'email' => 'an email address'];

    protected $fillable = ['organization_id', 'type', 'config', 'is_active', 'created_by_user_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => BusinessRuleType::class,
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The rule as a plain sentence, for the settings page, agents and the AI.
     *
     * @param  array<int, string>  $serviceNames  id => name
     */
    public function sentence(array $serviceNames = []): string
    {
        $c = $this->config;

        return match ($this->type) {
            BusinessRuleType::Instruction => (string) ($c['text'] ?? ''),
            BusinessRuleType::BookingCutoff => sprintf(
                "Don't book %s starting at or after %s.",
                isset($c['service_id']) && isset($serviceNames[$c['service_id']]) ? $serviceNames[$c['service_id']] : 'appointments',
                CarbonImmutable::createFromFormat('H:i', (string) ($c['time'] ?? '17:00'))->format('g:i A'),
            ),
            BusinessRuleType::BookingWindow => trim(implode(' ', array_filter([
                ! empty($c['min_notice_minutes']) ? 'Book at least '.self::duration((int) $c['min_notice_minutes']).' ahead.' : null,
                ! empty($c['max_days_ahead']) ? 'Book no more than '.(int) $c['max_days_ahead'].' days ahead.' : null,
            ]))),
            BusinessRuleType::ServiceArea => 'Only book visits in ZIP codes '.implode(', ', (array) ($c['postal_codes'] ?? [])).'.',
            BusinessRuleType::RequireDetail => 'Always get '.(self::DETAILS[$c['field'] ?? ''] ?? 'the details').' before booking.',
            BusinessRuleType::AutoEscalate => sprintf(
                'Escalate %s calls as "%s".',
                implode(', ', array_map(fn ($r) => mb_strtolower(CallLog::REASONS[$r] ?? $r), (array) ($c['reasons'] ?? []))),
                EscalationType::tryFrom((string) ($c['escalation_type'] ?? ''))?->label() ?? 'urgent',
            ),
        };
    }

    private static function duration(int $minutes): string
    {
        return $minutes % 60 === 0
            ? ($minutes / 60).' '.($minutes === 60 ? 'hour' : 'hours')
            : $minutes.' minutes';
    }
}
