<?php

namespace App\Services\Setup;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\SetupCompleted;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Notification;

/**
 * The setup wizard's steps and where a business is in them (spec ONB). Progress is saved after
 * every step, so people can leave and come back; SureHelp staff can see where each business is stuck.
 */
class SetupProgress
{
    /** step => [title, what it covers, can it be skipped] */
    public const STEPS = [
        'business' => ['Your business', 'Name, industry, timezone and contact details', false],
        'services' => ['Services', 'What you offer, how long it takes and what it costs', true],
        'hours' => ['Hours & area', 'When you\'re open, emergencies and where you work', false],
        'calls' => ['Call handling', 'How we answer, what we ask, FAQs and when to escalate', true],
        'calendar' => ['Calendar', 'Connect Google or Outlook so bookings land in your calendar', true],
        'team' => ['Team', 'Invite the people who should see calls and bookings', true],
        'review' => ['Go live', 'Check everything and hand over to SureHelp', false],
    ];

    public function __construct(private readonly Audit $audit) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::STEPS);
    }

    public function state(Organization $organization, string $step): ?string
    {
        return ($organization->setup_progress ?? [])[$step] ?? null;
    }

    public function mark(Organization $organization, string $step, string $state = 'done'): void
    {
        $organization->forceFill(['setup_progress' => array_merge($organization->setup_progress ?? [], [$step => $state])])->save();
    }

    /** Steps finished or skipped, out of the steps before "Go live". */
    public function count(Organization $organization): array
    {
        $steps = array_diff($this->keys(), ['review']);
        $done = count(array_intersect($steps, array_keys($organization->setup_progress ?? [])));

        return ['done' => $done, 'total' => count($steps)];
    }

    /** First step not done or skipped yet. */
    public function next(Organization $organization): string
    {
        foreach ($this->keys() as $step) {
            if ($step !== 'review' && $this->state($organization, $step) === null) {
                return $step;
            }
        }

        return 'review';
    }

    /** @return list<string> required steps still missing */
    public function missing(Organization $organization): array
    {
        return array_values(array_filter($this->keys(), fn (string $s) => $s !== 'review' && ! self::STEPS[$s][2] && $this->state($organization, $s) !== 'done'));
    }

    public function complete(Organization $organization, User $by): void
    {
        $organization->forceFill(['setup_completed_at' => now()])->save();
        $this->mark($organization, 'review');
        $this->audit->record('setup.completed', $organization, new: ['progress' => $organization->setup_progress], organization: $organization, actor: $by);

        $staff = User::query()->where('role', 'admin')->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermissionIn('organization.update'));
        Notification::send($staff, new SetupCompleted($organization));
    }
}
