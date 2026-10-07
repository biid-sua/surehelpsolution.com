<?php

namespace App\Actions\Customers;

use App\Models\AiRun;
use App\Models\Appointment;
use App\Models\AutomationRun;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerTimelineEvent;
use App\Models\Escalation;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * A customer asked the business to delete their personal information (spec §57, CCPA-style).
 * Their name and contact details are removed from the record and from every call, appointment,
 * task and escalation about them; the timeline and their inbox conversations are deleted; the record
 * is archived. Counts and
 * outcomes stay, so the business's results don't change.
 */
class EraseCustomer
{
    public const PLACEHOLDER = 'Erased customer';

    public function __construct(private readonly Audit $audit) {}

    public function handle(Customer $customer, User $actor): void
    {
        DB::transaction(function () use ($customer, $actor) {
            $scope = fn ($model) => $model::withoutGlobalScopes()->forOrganization($customer->organization_id)->where('customer_id', $customer->id);

            $calls = $scope(CallLog::class)->update([
                'caller_name' => self::PLACEHOLDER, 'caller_phone' => null, 'caller_email' => null,
                'service_location' => null, 'notes' => null,
            ]);
            $scope(Appointment::class)->update(['title' => 'Appointment ('.mb_strtolower(self::PLACEHOLDER).')', 'address' => null, 'notes' => null]);
            $scope(Task::class)->update(['title' => 'Task ('.mb_strtolower(self::PLACEHOLDER).')', 'description' => null]);
            $scope(Escalation::class)->update(['details' => null, 'resolution_notes' => null]);
            CustomerTimelineEvent::withoutGlobalScopes()->where('customer_id', $customer->id)->delete();

            // Their inbox conversations: messages and the AI assistant's records of them (tool inputs hold names and numbers).
            $conversations = Conversation::withoutGlobalScopes()->where('organization_id', $customer->organization_id)->where('customer_id', $customer->id)->pluck('id');
            AiRun::withoutGlobalScopes()->whereIn('conversation_id', $conversations)->delete();
            Conversation::withoutGlobalScopes()->whereIn('id', $conversations)->delete();   // messages and feedback cascade

            // Automation steps for this customer or their appointments (D50) never run after erasure.
            AutomationRun::withoutGlobalScopes()->where('organization_id', $customer->organization_id)
                ->where(fn ($q) => $q->where(fn ($c) => $c->where('subject_type', 'customer')->where('subject_id', $customer->id))
                    ->orWhere(fn ($a) => $a->where('subject_type', 'appointment')
                        ->whereIn('subject_id', Appointment::withoutGlobalScopes()->where('customer_id', $customer->id)->select('id'))))
                ->delete();
            $customer->tags()->detach();

            $customer->forceFill([
                'first_name' => self::PLACEHOLDER, 'last_name' => null, 'company' => null,
                'phone' => null, 'phone_e164' => null, 'email' => null,
                'address_line1' => null, 'address_line2' => null, 'city' => null, 'state' => null, 'postal_code' => null,
                'notes' => null, 'preferred_contact' => null,
                'sms_consent' => false, 'email_consent' => false,
                'erased_at' => now(),
            ])->saveQuietly();
            $customer->delete();

            // Only the record's id: the audit trail must not keep what was erased.
            $this->audit->record('customer.erased', $customer, new: ['calls_cleared' => $calls], actor: $actor, label: 'Customer '.$customer->ulid);
        });
    }
}
