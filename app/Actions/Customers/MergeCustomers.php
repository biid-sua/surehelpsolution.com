<?php

namespace App\Actions\Customers;

use App\Enums\TimelineEventType;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\CustomerTimelineEvent;
use App\Models\Escalation;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merge a duplicate customer into the one being kept (spec CRM-04). A person always chooses which
 * record stays. Everything linked to the duplicate moves over (calls, appointments, tasks,
 * escalations, timeline, tags); the kept record's details win, and its empty fields are filled
 * from the duplicate. The duplicate is archived (soft-deleted) and remembers where it went.
 */
class MergeCustomers
{
    /** Details copied when the kept record has none. */
    private const FILL = ['last_name', 'company', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'preferred_contact'];

    /** A customer outranks a prospect, which outranks a lead. */
    private const RANK = ['lead' => 1, 'prospect' => 2, 'customer' => 3, 'inactive' => 0, 'archived' => 0];

    public function __construct(private readonly RecordTimelineEvent $timeline, private readonly Audit $audit) {}

    public function handle(Customer $keep, Customer $duplicate, User $actor): Customer
    {
        if ($keep->is($duplicate) || $keep->organization_id !== $duplicate->organization_id) {
            throw ValidationException::withMessages(['merge' => 'Choose two different customers of the same business.']);
        }

        return DB::transaction(function () use ($keep, $duplicate, $actor) {
            $keep = Customer::query()->lockForUpdate()->findOrFail($keep->id);
            $duplicate = Customer::query()->lockForUpdate()->findOrFail($duplicate->id);

            $moved = [];
            foreach (['calls' => CallLog::class, 'appointments' => Appointment::class, 'tasks' => Task::class, 'escalations' => Escalation::class] as $label => $model) {
                $moved[$label] = $model::withoutGlobalScopes()->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id]);
            }
            $moved['timeline'] = CustomerTimelineEvent::withoutGlobalScopes()->where('customer_id', $duplicate->id)->update(['customer_id' => $keep->id]);
            $keep->tags()->syncWithoutDetaching($duplicate->tags()->pluck('tags.id'));

            // The duplicate gives up its phone first: phones are unique per business.
            $phone = [$duplicate->phone, $duplicate->phone_e164];
            $duplicate->forceFill(['phone_e164' => null, 'merged_into_id' => $keep->id])->saveQuietly();

            $filled = [];
            foreach (self::FILL as $field) {
                if (blank($keep->{$field}) && filled($field === 'phone' ? $phone[0] : $duplicate->{$field})) {
                    $keep->{$field} = $field === 'phone' ? $phone[0] : $duplicate->{$field};
                    $filled[] = $field;
                }
            }
            if (blank($keep->first_name) && filled($duplicate->first_name)) {
                $keep->first_name = $duplicate->first_name;
                $filled[] = 'first_name';
            }
            foreach (['email', 'sms'] as $channel) {
                if (! $keep->{$channel.'_consent'} && $duplicate->{$channel.'_consent'}) {
                    $keep->forceFill([$channel.'_consent' => true, $channel.'_consent_at' => $duplicate->{$channel.'_consent_at'}]);
                }
            }
            if (self::RANK[$duplicate->status->value] > self::RANK[$keep->status->value]) {
                $keep->status = $duplicate->status;
            }
            if (filled($duplicate->notes)) {
                $keep->notes = trim(($keep->notes ? $keep->notes."\n\n" : '').'From '.$duplicate->fullName().': '.$duplicate->notes);
            }
            $keep->last_activity_at = max($keep->last_activity_at, $duplicate->last_activity_at) ?: now();
            $keep->save();
            $duplicate->delete();

            $this->timeline->handle($keep, TimelineEventType::NoteAdded, 'Merged with '.$duplicate->fullName(),
                collect($moved)->filter()->map(fn (int $n, string $what) => $n.' '.$what)->join(', ') ?: null,
                meta: ['merged_customer_id' => $duplicate->id, 'filled' => $filled], actorId: $actor->id);
            $this->audit->record('customer.merged', $keep, ['merged' => $duplicate->ulid], ['moved' => $moved, 'filled' => $filled], label: $keep->fullName());

            return $keep->fresh();
        });
    }
}
