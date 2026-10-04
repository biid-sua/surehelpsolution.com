<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The one way to write the audit trail (spec §62).
 *
 * - Secrets are redacted (config/audit.php), never stored.
 * - The organization is taken from the subject, then from the tenant context.
 * - Auditing must never break the action being audited: failures are reported, not thrown.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?Organization $organization = null,
        ?User $actor = null,
        ?string $label = null,
    ): ?AuditLog {
        try {
            $actor ??= auth()->user();
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            return AuditLog::create([
                'organization_id' => $organization?->getKey() ?? $this->organizationOf($subject),
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor ? 'user' : (app()->runningInConsole() ? 'system' : 'guest'),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $label ?? $this->labelFor($subject),
                'old_values' => $old ? $this->redact($old) : null,
                'new_values' => $new ? $this->redact($new) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Record only the attributes that actually changed on a model (after save()).
     *
     * @param  list<string>|null  $only
     */
    public function changes(string $action, Model $subject, ?array $only = null, ?Organization $organization = null): ?AuditLog
    {
        // A record created by this save has no "changes"; its initial values are the change.
        $changed = $subject->wasRecentlyCreated
            ? array_keys($subject->getAttributes())
            : array_keys($subject->getChanges());
        $changed = array_values(array_diff($changed, ['id', 'created_at', 'updated_at', 'organization_id']));

        if ($only !== null) {
            $changed = array_values(array_intersect($changed, $only));
        }

        if ($changed === []) {
            return null;
        }

        // After save() the model's "original" is already the new state; getPrevious() holds the old raw values.
        $previous = $subject->getPrevious();
        $old = [];
        $new = [];
        foreach ($changed as $key) {
            if (! $subject->wasRecentlyCreated) {
                $old[$key] = $previous[$key] ?? null;
            }
            $value = $subject->getAttribute($key);
            $new[$key] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $this->record($action, $subject, $old, $new, $organization);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function redact(array $values): array
    {
        $secret = array_map('strtolower', config('audit.redact', []));

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $secret, true)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            } elseif ($value instanceof \DateTimeInterface) {
                $values[$key] = $value->format(DATE_ATOM);
            }
        }

        return $values;
    }

    private function organizationOf(?Model $subject): ?int
    {
        if ($subject instanceof Organization) {
            return $subject->getKey();
        }

        if ($subject && $subject->getAttribute('organization_id')) {
            return (int) $subject->getAttribute('organization_id');
        }

        return app(CurrentOrganization::class)->id();
    }

    private function labelFor(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        foreach (['call_id', 'name', 'title', 'label', 'email'] as $attribute) {
            if ($value = $subject->getAttribute($attribute)) {
                return Str::limit((string) $value, 250, '');
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}
