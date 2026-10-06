<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who should take a course (brief §1.7–1.8, D42): every agent, everyone with a role, the agents
 * currently serving a company, or one agent. Rules are turned into one training assignment per
 * agent, now and whenever someone new matches. A company rule also sets how strictly that
 * company's readiness is enforced (D43).
 */
class TrainingRule extends Model
{
    public const EVERYONE = 'everyone';

    public const ROLE = 'role';

    public const COMPANY = 'company';

    public const AGENT = 'agent';

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];

    public const ENFORCEMENT = [
        'informational' => 'Informational: shown only',
        'warning' => 'Warning: a reminder in the company workspace',
        'restricted' => 'Restricted: no calls or bookings until done',
        'blocking' => 'Blocking: only the company training is open',
    ];

    public const ROLES = ['agent' => 'Agents', 'agent_supervisor' => 'Agent supervisors'];

    protected $fillable = [
        'course_id', 'scope', 'role', 'organization_id', 'agent_user_id', 'is_required', 'priority', 'due_days', 'enforcement', 'is_active', 'created_by_user_id',
    ];

    protected $attributes = ['is_required' => true, 'priority' => 'normal', 'enforcement' => 'warning', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<TrainingCourse, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function audience(): string
    {
        return match ($this->scope) {
            self::EVERYONE => 'Every agent',
            self::ROLE => self::ROLES[$this->role] ?? (string) $this->role,
            self::COMPANY => 'Agents serving '.($this->organization->name ?? 'a company'),
            default => $this->agent->name ?? 'One agent',
        };
    }
}
