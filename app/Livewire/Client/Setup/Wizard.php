<?php

namespace App\Livewire\Client\Setup;

use App\Actions\Team\InviteMember;
use App\Enums\BusinessRuleType;
use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Enums\ServicePriceType;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\Business\BusinessHours;
use App\Services\Calendar\CalendarManager;
use App\Services\Rules\BusinessRules;
use App\Services\Setup\SetupProgress;
use App\Support\Audit\Audit;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Self-serve setup for a new business (spec ONB-01..08): seven short steps with a progress bar.
 * Each step saves into the same records as the Business pages, so everything can be changed later,
 * and progress is kept, so the owner can leave and come back.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Set up SureHelp')]
class Wizard extends Component
{
    use ScopedToOrganization;

    /** Wizard-made knowledge items carry this category, so saving a step again replaces them. */
    private const SOURCE = 'Setup';

    #[Url]
    public string $step = '';

    /** @var array{name: string, industry: string, timezone: string, phone: string, email: string, website: string, description: string, job_value: string} */
    public array $business = ['name' => '', 'industry' => '', 'timezone' => '', 'phone' => '', 'email' => '', 'website' => '', 'description' => '', 'job_value' => ''];

    /** @var list<array{name: string, minutes: int|string, type: string, price: string, selected: bool, bookable: bool}> */
    public array $services = [];

    /** @var array<int, array{open: bool, opens: string, closes: string}> */
    public array $days = [];

    public bool $emergencyAvailable = false;

    public string $emergencyInstructions = '';

    public string $zips = '';

    public string $greeting = '';

    /** @var list<string> */
    public array $details = [];

    /** @var list<array{q: string, a: string}> */
    public array $faqs = [];

    public string $dos = '';

    public string $escalation = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'staff';

    public function mount(SetupProgress $progress): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        if (! array_key_exists($this->step, SetupProgress::STEPS)) {
            $this->step = $progress->next($organization);
        }
        $this->load($organization);
    }

    public function go(string $step): void
    {
        abort_unless(array_key_exists($step, SetupProgress::STEPS), 404);
        $this->resetValidation();
        $this->step = $step;
    }

    public function skip(SetupProgress $progress): void
    {
        abort_unless(SetupProgress::STEPS[$this->step][2] ?? false, 422);
        if ($progress->state($this->organization(), $this->step) === null) {
            $progress->mark($this->organization(), $this->step, 'skipped');
        }
        $this->advance();
    }

    // Steps ───────────────────────────────────────────────────────────────────

    public function saveBusiness(SetupProgress $progress, Audit $audit): void
    {
        $this->authorize('organization.update', $this->organization());
        $data = $this->validate([
            'business.name' => ['required', 'string', 'max:255'],
            'business.industry' => ['required', Rule::in(array_keys(config('industries')))],
            'business.timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
            'business.phone' => ['nullable', 'string', 'max:30'],
            'business.email' => ['nullable', 'email', 'max:255'],
            'business.website' => ['nullable', 'url:http,https', 'max:255'],
            'business.description' => ['nullable', 'string', 'max:1000'],
            'business.job_value' => ['nullable', 'string', 'max:20'],
        ], ['business.website.url' => 'Enter the full address, like https://riveraplumbing.com.'], [
            'business.name' => 'business name', 'business.industry' => 'industry', 'business.timezone' => 'timezone',
            'business.phone' => 'phone', 'business.email' => 'email', 'business.website' => 'website', 'business.job_value' => 'average job value',
        ])['business'];

        $jobValue = null;
        if (filled($data['job_value'] ?? null)) {
            try {
                $jobValue = Money::parse($data['job_value']);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['business.job_value' => 'Enter an amount like 250 or 250.00.']);
            }
        }

        $organization = $this->organization();
        $nullable = fn (string $key) => filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        DB::transaction(function () use ($organization, $data, $nullable, $jobValue, $audit) {
            $organization->forceFill(['name' => trim($data['name']), 'timezone' => $data['timezone'], 'average_job_value_cents' => $jobValue])->save();
            $profile = BusinessProfile::firstOrNew(['organization_id' => $organization->id]);
            $profile->fill([
                'display_name' => trim($data['name']),
                'industry' => config("industries.{$data['industry']}.label"),
                'phone' => $nullable('phone'),
                'email' => $nullable('email'),
                'website' => $nullable('website'),
                'description' => $nullable('description'),
            ])->save();
            $audit->changes('business_profile.updated', $profile, ['display_name', 'industry', 'phone', 'email', 'website', 'description']);
        });

        // A new industry means new suggestions on the next steps.
        $this->services = $this->templateServices($data['industry'], $organization);
        $this->prefillFromIndustry($data['industry'], $organization);

        $progress->mark($organization, 'business');
        $this->advance();
    }

    public function saveServices(SetupProgress $progress, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $chosen = array_filter($this->services, fn (array $s) => (bool) $s['selected']);
        if ($chosen === [] && ! BusinessService::query()->forOrganization($organization)->exists()) {
            throw ValidationException::withMessages(['services' => 'Pick at least one service, or skip this step for now.']);
        }

        $rows = [];
        foreach ($chosen as $i => $service) {
            $type = ServicePriceType::tryFrom($service['type']) ?? ServicePriceType::QuoteRequired;
            if (trim($service['name']) === '') {
                throw ValidationException::withMessages(["services.$i.name" => 'Give the service a name.']);
            }
            $minutes = (int) $service['minutes'];
            if ($minutes < 5 || $minutes > 1440) {
                throw ValidationException::withMessages(["services.$i.minutes" => 'Between 5 and 1440 minutes.']);
            }
            try {
                $cents = $type->needsAmount() ? Money::parse((string) $service['price']) : null;
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(["services.$i.price" => 'Enter a price like 149.']);
            }
            $rows[] = ['name' => trim($service['name']), 'minutes' => $minutes, 'type' => $type, 'cents' => $cents, 'bookable' => $service['bookable']];
        }

        $existing = BusinessService::query()->forOrganization($organization)->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        foreach ($rows as $i => $row) {
            if (in_array(mb_strtolower($row['name']), $existing, true)) {
                continue;   // already there (step saved twice): keep the business's own edits
            }
            $service = BusinessService::create([
                'organization_id' => $organization->id,
                'name' => $row['name'],
                'duration_minutes' => $row['minutes'],
                'buffer_minutes' => 0,
                'price_type' => $row['type'],
                'price_cents' => $row['cents'],
                'currency' => $organization->currency,
                'is_active' => true,
                'is_bookable' => $row['bookable'],
                'sort_order' => $i,
            ]);
            $audit->record('service.created', $service, new: ['name' => $service->name], organization: $organization);
        }

        $progress->mark($organization, 'services');
        $this->services = $this->templateServices(null, $organization);
        $this->advance();
    }

    public function applyHoursPreset(string $preset): void
    {
        $presets = [
            'weekdays' => [1, 2, 3, 4, 5],
            'six' => [1, 2, 3, 4, 5, 6],
            'always' => [0, 1, 2, 3, 4, 5, 6],
        ];
        $open = $presets[$preset] ?? abort(404);
        foreach (array_keys(BusinessHours::DAYS) as $day) {
            $this->days[$day] = $preset === 'always'
                ? ['open' => true, 'opens' => '00:00', 'closes' => '23:59']
                : ['open' => in_array($day, $open, true), 'opens' => '08:00', 'closes' => $day === 6 ? '14:00' : '17:00'];
        }
    }

    public function saveHours(SetupProgress $progress, Audit $audit, BusinessHours $hours, BusinessRules $rules): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);

        $errors = [];
        foreach ($this->days as $day => $d) {
            if (! $d['open']) {
                continue;
            }
            if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $d['opens']) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $d['closes']) || $d['opens'] === $d['closes']) {
                $errors["days.$day"] = 'Enter an opening and a different closing time.';
            }
        }
        if (! collect($this->days)->contains('open', true)) {
            $errors['days'] = 'Open on at least one day. Agents use these hours on every call.';
        }
        $codes = array_values(array_unique(preg_split('/[\s,;]+/', trim($this->zips), -1, PREG_SPLIT_NO_EMPTY) ?: []));
        if ($bad = array_filter($codes, fn ($z) => ! preg_match('/^\d{5}$/', $z))) {
            $errors['zips'] = 'Use 5-digit ZIP codes separated by commas or spaces ('.implode(', ', array_slice($bad, 0, 3)).' isn\'t one).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $this->validate(['emergencyInstructions' => ['nullable', 'string', 'max:2000']]);

        DB::transaction(function () use ($organization, $codes, $audit) {
            BusinessHour::query()->forOrganization($organization)->whereNull('location_id')->delete();
            foreach ($this->days as $day => $d) {
                if ($d['open']) {
                    BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => $d['opens'], 'closes_at' => $d['closes']]);
                }
            }
            BusinessProfile::firstOrNew(['organization_id' => $organization->id])->fill([
                'emergency_available' => $this->emergencyAvailable,
                'emergency_instructions' => filled($this->emergencyInstructions) ? trim($this->emergencyInstructions) : null,
            ])->save();

            BusinessRule::query()->forOrganization($organization)->where('type', BusinessRuleType::ServiceArea->value)->delete();
            if ($codes) {
                BusinessRule::create(['organization_id' => $organization->id, 'type' => BusinessRuleType::ServiceArea, 'config' => ['postal_codes' => $codes], 'created_by_user_id' => auth()->id()]);
            }
            $audit->record('business_hours.updated', $organization, new: ['source' => 'setup', 'days_open' => collect($this->days)->where('open', true)->count(), 'zip_codes' => count($codes)]);
        });
        $hours->forget($organization);
        $rules->forget();

        $progress->mark($organization, 'hours');
        $this->advance();
    }

    public function addFaq(): void
    {
        $this->faqs[] = ['q' => '', 'a' => ''];
    }

    public function removeFaq(int $i): void
    {
        unset($this->faqs[$i]);
        $this->faqs = array_values($this->faqs);
    }

    public function saveCalls(SetupProgress $progress, Audit $audit, BusinessRules $rules): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        $this->validate([
            'greeting' => ['nullable', 'string', 'max:500'],
            'details' => ['array'],
            'details.*' => [Rule::in(array_keys(BusinessRule::DETAILS))],
            'faqs.*.q' => ['nullable', 'string', 'max:255'],
            'faqs.*.a' => ['nullable', 'string', 'max:2000'],
            'dos' => ['nullable', 'string', 'max:2000'],
            'escalation' => ['nullable', 'string', 'max:1000'],
        ], [], ['faqs.*.q' => 'question', 'faqs.*.a' => 'answer']);
        foreach ($this->faqs as $i => $faq) {
            if (filled($faq['q']) && blank($faq['a'])) {
                throw ValidationException::withMessages(["faqs.$i.a" => 'Add the answer agents should give, or remove this question.']);
            }
        }

        DB::transaction(function () use ($organization, $audit) {
            // Saving this step again replaces what it created before; items made on the Knowledge page stay.
            KnowledgeItem::query()->forOrganization($organization)->where('category', self::SOURCE)->delete();
            $item = fn (KnowledgeType $type, string $title, string $content, KnowledgeVisibility $visibility, bool $pinned = false, int $order = 0) => KnowledgeItem::create([
                'organization_id' => $organization->id, 'type' => $type, 'title' => $title, 'content' => trim($content), 'category' => self::SOURCE,
                'visibility' => $visibility, 'is_pinned' => $pinned, 'is_active' => true, 'sort_order' => $order, 'created_by_user_id' => auth()->id(),
            ]);

            if (filled($this->greeting)) {
                $item(KnowledgeType::AgentInstruction, 'How to answer the phone', $this->greeting, KnowledgeVisibility::Internal, true);
            }
            foreach (array_values(array_filter($this->faqs, fn (array $f) => filled($f['q']))) as $i => $faq) {
                $item(KnowledgeType::Faq, trim($faq['q']), $faq['a'], KnowledgeVisibility::Public, false, $i);
            }
            if (filled($this->escalation)) {
                $item(KnowledgeType::EscalationRule, 'When to escalate to us', $this->escalation, KnowledgeVisibility::Internal, true);
            }

            // Details to collect before booking: one enforced rule each.
            $existing = BusinessRule::query()->forOrganization($organization)->where('type', BusinessRuleType::RequireDetail->value)->get();
            foreach ($existing as $rule) {
                if (! in_array($rule->config['field'] ?? null, $this->details, true)) {
                    $rule->delete();
                }
            }
            foreach ($this->details as $field) {
                if (! $existing->contains(fn (BusinessRule $r) => ($r->config['field'] ?? null) === $field)) {
                    BusinessRule::create(['organization_id' => $organization->id, 'type' => BusinessRuleType::RequireDetail, 'config' => ['field' => $field], 'created_by_user_id' => auth()->id()]);
                }
            }

            // Do's and don'ts: one instruction per line, replacing the ones this step made before.
            BusinessRule::query()->forOrganization($organization)->where('type', BusinessRuleType::Instruction->value)->get()
                ->filter(fn (BusinessRule $r) => ($r->config['source'] ?? null) === 'setup')->each->delete();
            foreach (array_filter(array_map('trim', preg_split('/\R/', $this->dos) ?: [])) as $line) {
                BusinessRule::create(['organization_id' => $organization->id, 'type' => BusinessRuleType::Instruction, 'config' => ['text' => mb_substr($line, 0, 500), 'source' => 'setup'], 'created_by_user_id' => auth()->id()]);
            }
            $audit->record('setup.call_handling_saved', $organization, new: ['faqs' => count(array_filter($this->faqs, fn (array $f) => filled($f['q']))), 'details' => $this->details]);
        });
        $rules->forget();

        $progress->mark($organization, 'calls');
        $this->advance();
    }

    public function continueCalendar(SetupProgress $progress): void
    {
        $organization = $this->organization();
        $connected = $organization->calendarConnections()->exists();
        $progress->mark($organization, 'calendar', $connected ? 'done' : 'skipped');
        $this->advance();
    }

    public function invite(InviteMember $invite): void
    {
        $this->authorize('users.create', $this->organization());
        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteRole' => ['required', Rule::in(array_keys(OrganizationInvitation::ROLES))],
        ], [], ['inviteEmail' => 'email', 'inviteRole' => 'role']);
        $invite->handle($this->organization(), $this->inviteEmail, $this->inviteRole, auth()->user());
        $this->dispatch('toast', type: 'success', message: "Invitation sent to {$this->inviteEmail}.");
        $this->reset('inviteEmail');
    }

    public function continueTeam(SetupProgress $progress): void
    {
        $organization = $this->organization();
        $invited = OrganizationInvitation::query()->where('organization_id', $organization->id)->exists() || $organization->members()->count() > 1;
        $progress->mark($organization, 'team', $invited ? 'done' : 'skipped');
        $this->advance();
    }

    public function finish(SetupProgress $progress): void
    {
        $organization = $this->organization();
        $this->authorize('organization.update', $organization);
        if ($missing = $progress->missing($organization)) {
            throw ValidationException::withMessages(['finish' => 'Finish these first: '.collect($missing)->map(fn ($s) => SetupProgress::STEPS[$s][0])->join(', ').'.']);
        }

        $progress->complete($organization, auth()->user());
        session()->flash('status', 'You\'re all set. SureHelp will check your details and confirm when your line is live.');
        $this->redirectRoute('app.dashboard');
    }

    public function render(SetupProgress $progress, CalendarManager $calendars): View
    {
        $organization = $this->organization();
        $organization->refresh();

        return view('livewire.client.setup.wizard', [
            'organization' => $organization,
            'steps' => SetupProgress::STEPS,
            'progress' => $progress,
            'count' => $progress->count($organization),
            'industries' => collect(config('industries'))->map(fn (array $i) => $i['label'])->all(),
            'priceTypes' => collect(ServicePriceType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all(),
            'dayNames' => BusinessHours::DAYS,
            'detailOptions' => BusinessRule::DETAILS,
            'existingServices' => $this->step === 'services' ? BusinessService::query()->forOrganization($organization)->orderBy('sort_order')->get(['name', 'duration_minutes', 'price_type', 'price_cents', 'currency']) : collect(),
            'calendarProviders' => collect($calendars->providers())->filter(fn ($p) => $p->isConfigured())->map(fn ($p) => $p->label())->all(),
            'connections' => $organization->calendarConnections()->get(['provider', 'account_email', 'status']),
            'members' => $organization->members()->wherePivot('status', 'active')->get(['users.id', 'users.name', 'users.email']),
            'invitations' => OrganizationInvitation::query()->where('organization_id', $organization->id)->pending()->get(['email', 'role']),
            'missing' => $progress->missing($organization),
            'knowledgeCount' => KnowledgeItem::query()->forOrganization($organization)->count(),
        ]);
    }

    // Helpers ───────────────────────────────────────────────────────────────────

    private function advance(): void
    {
        $this->resetValidation();
        $organization = $this->organization()->refresh();
        $keys = array_keys(SetupProgress::STEPS);
        $next = $keys[array_search($this->step, $keys, true) + 1] ?? 'review';
        $this->step = app(SetupProgress::class)->state($organization, $next) === null ? $next : app(SetupProgress::class)->next($organization);
    }

    private function load(Organization $organization): void
    {
        $profile = BusinessProfile::query()->forOrganization($organization)->first();
        $industry = collect(config('industries'))->search(fn (array $i) => $i['label'] === $profile?->industry) ?: '';
        $this->business = [
            'name' => $organization->name,
            'industry' => (string) $industry,
            'timezone' => (string) $organization->timezone,
            'phone' => (string) $profile?->phone,
            'email' => (string) ($profile?->email ?: $organization->owner?->email),
            'website' => (string) $profile?->website,
            'description' => (string) $profile?->description,
            'job_value' => $organization->average_job_value_cents ? Money::toInput($organization->average_job_value_cents) : '',
        ];
        $this->services = $this->templateServices($industry ?: null, $organization);

        foreach (array_keys(BusinessHours::DAYS) as $day) {
            $this->days[$day] = ['open' => false, 'opens' => '08:00', 'closes' => '17:00'];
        }
        $saved = BusinessHour::query()->forOrganization($organization)->whereNull('location_id')->orderBy('opens_at')->get();
        if ($saved->isEmpty()) {
            $this->applyHoursPreset('weekdays');
        }
        foreach ($saved->groupBy('day_of_week') as $day => $intervals) {
            $this->days[$day] = ['open' => true, 'opens' => substr($intervals->first()->opens_at, 0, 5), 'closes' => substr($intervals->last()->closes_at, 0, 5)];
        }
        $this->emergencyAvailable = (bool) $profile?->emergency_available;
        $this->emergencyInstructions = (string) $profile?->emergency_instructions;
        $area = BusinessRule::query()->forOrganization($organization)->where('type', BusinessRuleType::ServiceArea->value)->first();
        $this->zips = implode(', ', $area->config['postal_codes'] ?? []);

        $mine = KnowledgeItem::query()->forOrganization($organization)->where('category', self::SOURCE)->orderBy('sort_order')->get();
        $this->greeting = (string) $mine->firstWhere('type', KnowledgeType::AgentInstruction)?->content;
        $this->escalation = (string) $mine->firstWhere('type', KnowledgeType::EscalationRule)?->content;
        $this->faqs = $mine->where('type', KnowledgeType::Faq)->map(fn (KnowledgeItem $k) => ['q' => $k->title, 'a' => $k->content])->values()->all();
        $this->details = BusinessRule::query()->forOrganization($organization)->where('type', BusinessRuleType::RequireDetail->value)->get()
            ->map(fn (BusinessRule $r) => (string) ($r->config['field'] ?? ''))->filter()->values()->all();
        $this->dos = BusinessRule::query()->forOrganization($organization)->where('type', BusinessRuleType::Instruction->value)->get()
            ->filter(fn (BusinessRule $r) => ($r->config['source'] ?? null) === 'setup')->map(fn (BusinessRule $r) => $r->config['text'])->join("\n");

        if ($industry && $this->state($organization, 'calls') === null) {
            $this->prefillFromIndustry($industry, $organization);
        }
    }

    /** Suggestions on the call-handling step from the industry, until the owner has saved that step. */
    private function prefillFromIndustry(string $industry, Organization $organization): void
    {
        if ($this->state($organization, 'calls') !== null) {
            return;
        }
        $template = config("industries.$industry");
        $name = $organization->name;
        $this->greeting = $this->greeting ?: "Thank you for calling {$name}, this is [your name]. How can I help you today?";
        $this->faqs = $this->faqs ?: array_map(fn (array $f) => ['q' => $f[0], 'a' => $f[1]], $template['faqs'] ?? []);
        $this->details = $this->details ?: ($template['details'] ?? []);
        if (blank($this->emergencyInstructions) && filled($template['emergencies'] ?? '')) {
            $this->emergencyInstructions = $template['emergencies'];
            $this->emergencyAvailable = true;
        }
    }

    /** @return list<array{name: string, minutes: int, type: string, price: string, selected: bool, bookable: bool}> */
    private function templateServices(?string $industry, Organization $organization): array
    {
        $have = BusinessService::query()->forOrganization($organization)->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        return collect(config("industries.$industry.services", []))
            ->reject(fn (array $s) => in_array(mb_strtolower($s['name']), $have, true))
            ->map(fn (array $s) => [
                'name' => $s['name'], 'minutes' => $s['minutes'], 'type' => $s['type'],
                'price' => $s['price'] === null ? '' : (string) $s['price'], 'selected' => true, 'bookable' => $s['bookable'],
            ])->values()->all();
    }

    private function state(Organization $organization, string $step): ?string
    {
        return app(SetupProgress::class)->state($organization, $step);
    }
}
