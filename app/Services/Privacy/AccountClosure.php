<?php

namespace App\Services\Privacy;

use App\Enums\OrganizationStatus;
use App\Models\DataExport;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AccountClosureScheduled;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Closing a business account (task.md CMP-07). The owner asks; nothing is deleted for 30 days
 * and they can change their mind. Then the business's customers, calls, appointments, settings and
 * connections are deleted (including inbox messages, AI records, social accounts and their tokens,
 * uploaded media and websites), its people's sign-ins are removed, and the subscription ends.
 * Agent training records stay: they are about SureHelp's agents, not the business's customers.
 * Invoices and payments are kept: tax law requires it.
 */
class AccountClosure
{
    public const GRACE_DAYS = 30;

    /** Business data deleted on closure, every row with the business's organization_id. */
    private const TABLES = [
        // Inbox, AI assistant, social and websites (D37–D39, D33, D44): messages and AI tool calls hold
        // personal data; social accounts hold access tokens.
        'ai_feedback', 'ai_guidelines', 'messages', 'ai_runs', 'conversations', 'ai_assistants', 'chat_widgets',
        'social_posts', 'social_accounts', 'websites',
        // Support requests (D49); their attachment files are removed with the business's folder below.
        'support_ticket_messages', 'support_tickets',
        // Automations and their history (D50).
        'automation_runs', 'automations',
        'call_logs', 'appointments', 'tasks', 'escalations', 'customer_timeline_events', 'customers', 'tags',
        'knowledge_items', 'business_rules', 'business_services', 'business_hours', 'business_holidays',
        'business_locations', 'business_profiles', 'message_templates', 'calendar_busy_blocks', 'calendar_connections',
        'call_outcomes', 'organization_invitations', 'agent_assignments',
    ];

    public function __construct(private readonly Audit $audit) {}

    public function request(Organization $organization, User $owner, string $typedName, string $password): void
    {
        $errors = [];
        if (mb_strtolower(trim($typedName)) !== mb_strtolower(trim($organization->name))) {
            $errors['confirmName'] = 'Type the business name exactly as shown.';
        }
        if (! Hash::check($password, (string) $owner->password)) {
            $errors['password'] = 'That password isn\'t right.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $organization->forceFill([
            'closure_requested_at' => now(),
            'closes_at' => now()->addDays(self::GRACE_DAYS),
            'closure_requested_by' => $owner->id,
        ])->save();
        $this->audit->record('organization.closure_requested', $organization, new: ['closes_at' => $organization->closes_at->toDateString()], actor: $owner);

        $owner->notify(new AccountClosureScheduled($organization));
    }

    public function cancel(Organization $organization, User $owner): void
    {
        $organization->forceFill(['closure_requested_at' => null, 'closes_at' => null, 'closure_requested_by' => null])->save();
        $this->audit->record('organization.closure_cancelled', $organization, actor: $owner);
    }

    public function close(Organization $organization): void
    {
        $exports = DataExport::query()->where('organization_id', $organization->id)->get();

        DB::transaction(function () use ($organization) {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('organization_id', $organization->id)->delete();
            }

            foreach ($organization->members()->get() as $member) {
                $organization->members()->detach($member->id);
                if ($member->isClient() && ! $member->organizations()->exists()) {
                    $this->forget($member);
                }
            }

            $organization->subscriptions()->whereNot('status', 'cancelled')
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_at_period_end' => false]);

            $organization->forceFill([
                'status' => OrganizationStatus::Cancelled,
                'closed_at' => now(),
                'setup_progress' => null,
            ])->save();

            $this->audit->record('organization.closed', $organization, label: $organization->name);
        });

        // Uploaded photos and videos: through the model, so each file is removed from storage too.
        MediaAsset::withoutGlobalScopes()->where('organization_id', $organization->id)->each(fn (MediaAsset $asset) => $asset->delete());
        Storage::disk('local')->deleteDirectory('support/'.$organization->id);
        Storage::disk('local')->deleteDirectory('logos/'.$organization->id);

        foreach ($exports as $export) {
            if ($export->path) {
                Storage::disk('local')->delete($export->path);
            }
            $export->delete();
        }
    }

    /**
     * A person who only worked for this business: their account can't sign in again and no
     * longer holds their name or email. The row stays so past records keep a valid reference.
     */
    private function forget(User $user): void
    {
        $user->tokens()->delete();
        $user->notifications()->delete();
        $user->forceFill([
            'name' => 'Former user',
            'phone' => null,
            'email' => 'closed-'.$user->id.'@invalid.surehelp',
            'password' => Hash::make(Str::random(40)),
            'is_active' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
            'session_epoch' => ($user->session_epoch ?? 0) + 1,
        ])->saveQuietly();
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}
