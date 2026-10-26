<?php

namespace Database\Seeders;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Assignments\AssignAgent;
use App\Actions\Billing\ManageAddons;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Billing\RecordPayment;
use App\Actions\Calls\LogCall;
use App\Actions\Escalations\AcknowledgeEscalation;
use App\Actions\Escalations\RaiseEscalation;
use App\Actions\Escalations\ResolveEscalation;
use App\Actions\Inbox\ReceiveMessage;
use App\Actions\Inbox\SendReply;
use App\Actions\Organizations\ChangeOrganizationStatus;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CreateTask;
use App\Enums\AppointmentStatus;
use App\Enums\EscalationType;
use App\Enums\InboxChannel;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationStatus;
use App\Enums\PaymentMethod;
use App\Enums\QuestionType;
use App\Enums\SocialNetwork;
use App\Enums\SupportTicketStatus;
use App\Enums\TaskStatus;
use App\Exceptions\SlotUnavailable;
use App\Jobs\FinishVoiceCall;
use App\Models\Addon;
use App\Models\AgentDutySchedule;
use App\Models\AiAssistant;
use App\Models\AiRun;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\CalendarBusyBlock;
use App\Models\CalendarConnection;
use App\Models\CallLog;
use App\Models\CallOutcome;
use App\Models\ChatWidget;
use App\Models\ContactSubmission;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\Escalation;
use App\Models\Invoice;
use App\Models\KnowledgeItem;
use App\Models\LegalAcceptance;
use App\Models\MediaAsset;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\Plan;
use App\Models\QaReview;
use App\Models\Review;
use App\Models\SearchConsoleConnection;
use App\Models\SeoDailyMetric;
use App\Models\SeoTask;
use App\Models\ShiftRequest;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TrainingModule;
use App\Models\TrainingPath;
use App\Models\TrainingQuestion;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\Account\LegalDocuments;
use App\Services\Automation\AutomationEngine;
use App\Services\Billing\BillingRun;
use App\Services\Billing\FeatureAccess;
use App\Services\Quality\QualityReviews;
use App\Services\Support\SupportDesk;
use App\Services\Telephony\Texting;
use App\Services\Training\CoursePublisher;
use App\Services\Training\LearningProgress;
use App\Services\Training\TrainingAssigner;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplPriorityQueue;

/**
 * Demo data for recording the product (every client, agent and admin screen).
 *
 * Run on a fresh database that already has the default DatabaseSeeder data:
 *   php artisan migrate:fresh --force && php artisan db:seed --force && php artisan db:seed --class=DemoSeeder --force
 *
 * The seeder travels through time (Carbon::setTestNow) and replays about four months of a busy
 * answering service through the app's own actions, so timestamps, audit entries, customer
 * timelines, invoices and notifications all line up. Nothing leaves the machine: mail goes to the
 * array mailer, queued work runs inline, outbound HTTP is refused and the AI provider stays off.
 *
 * Sandbox mode (D94, Admin › Demo data, App\Services\Demo\DemoData) loads the same story into a
 * system that already has real data: it creates its own staff accounts (with unusable passwords)
 * instead of renaming the default ones, leaves real plans, feature settings, courses, shifts and
 * legal records alone, and only touches rows it created itself.
 */
class DemoSeeder extends Seeder
{
    private const TZ = 'America/Chicago';

    public const TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private CarbonImmutable $now;

    /** Files written to storage, for removal with the demo data (D94). @var list<string> */
    public array $files = [];

    public function __construct(public bool $sandbox = false) {}

    private CarbonImmutable $clock;

    /** @var SplPriorityQueue<array{0: int, 1: int}, array{0: CarbonImmutable, 1: Closure}> */
    private SplPriorityQueue $queue;

    private int $seq = 0;

    private User $admin;

    private User $john;

    private User $mike;

    private User $owner;

    private User $manager;

    private User $staff;

    private Organization $rivera;

    /** @var array<string, Organization> */
    private array $orgs = [];

    /** @var array<string, array<string, mixed>> */
    private array $specs = [];

    /** @var array<string, list<array<string, mixed>>> people not yet heard from, per business */
    private array $pool = [];

    /** @var array<string, array<string, array<string, mixed>>> people already known, per business, by phone */
    private array $known = [];

    /** @var array<string, array<string, BusinessService>> */
    private array $services = [];

    /** @var array<string, array<string, BusinessLocation>> */
    private array $locations = [];

    /** @var array<string, list<array{0: int, 1: int}>> booked intervals per business + location */
    private array $busy = [];

    /** @var array<string, true> */
    private array $sweeps = [];

    /** @var array<int, true> invoices with a payment planned or deliberately left open */
    private array $invoiceHandled = [];

    /** @var array<string, Plan> */
    private array $plans = [];

    /** @var array<string, Addon> */
    private array $addons = [];

    /** @var list<string> */
    private array $summary = [];

    public function run(): void
    {
        $started = microtime(true);
        $this->quietOutside();
        mt_srand(20261015);

        $this->now = CarbonImmutable::now()->second(0)->startOfMinute();
        $this->clock = $this->now;
        $this->queue = new SplPriorityQueue;

        if (Organization::query()->withTrashed()->where('name', 'Rivera Plumbing & Heating')->exists()) {
            if ($this->sandbox) {
                throw new \RuntimeException('A business called Rivera Plumbing & Heating already exists, so the demo data would clash with it.');
            }
            $this->command->error('The demo data is already here. Start again with: php artisan migrate:fresh --force && php artisan db:seed --class=DemoSeeder --force');

            return;
        }

        // The default users come from UserSeeder; run it when this database doesn't have them yet.
        if (! $this->sandbox && ! User::query()->where('email', 'admin@surehelpsolution.com')->exists()) {
            $this->call(UserSeeder::class);
        }

        try {
            $this->users();
            $this->catalogue();
            $this->training();

            $this->businesses();
            $this->rivera();
            $this->otherBusinesses();
            $this->planCalls();
            $this->planRiveraExtras();
            $this->planBilling();
            $this->planInbox();
            $this->planSupport();
            $this->planQuality();

            $this->drain();

            $this->travel($this->now);
            $this->finishCustomers();
            $this->social();
            $this->reviews();
            $this->website();
            $this->seo();
            $this->calendar();
            $this->phoneLines();
            $this->schedules();
            $this->enquiries();
            $this->accountGates();
            $this->tidyAutomationTasks();
        } finally {
            Carbon::setTestNow();
        }

        if (! $this->sandbox) {   // loaded from Admin › Demo data: no console to print to
            $this->report($started);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Safety and time travel
    // ---------------------------------------------------------------------------------------------

    /** Nothing leaves the machine while the demo is built. */
    private function quietOutside(): void
    {
        config([
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'broadcasting.default' => 'null',
            'ai.anthropic.api_key' => null,
            'tenancy.auto_assign_agents' => false,
        ]);
        Http::preventStrayRequests();
    }

    private function travel(CarbonImmutable $at): void
    {
        $this->clock = $at;
        Carbon::setTestNow($at);
    }

    /** Schedule something to happen at a moment in the past (future moments are dropped). */
    private function at(CarbonImmutable $when, Closure $what): void
    {
        if ($when->greaterThan($this->now)) {
            return;
        }
        if (isset($this->clock) && $when->lessThan($this->clock) && $this->seq > 0 && $this->draining) {
            $when = $this->clock;
        }
        $this->seq++;
        $this->queue->insert([$when, $what], [-$when->getTimestamp(), -$this->seq]);
    }

    private bool $draining = false;

    private function drain(): void
    {
        $this->draining = true;
        while (! $this->queue->isEmpty()) {
            /** @var array{0: CarbonImmutable, 1: Closure} $event */
            $event = $this->queue->extract();
            $this->travel($event[0]);
            try {
                ($event[1])();
            } catch (\Throwable $e) {
                $where = collect($e->getTrace())->filter(fn (array $f) => str_ends_with($f['file'] ?? '', 'DemoSeeder.php'))->map(fn (array $f) => $f['line'])->take(4)->implode(' < ');
                throw new \RuntimeException('Demo event at '.$event[0]->toDateTimeString().' failed: '.$e->getMessage().' (DemoSeeder line '.$where.')', 0, $e);
            }
        }
        $this->draining = false;
    }

    /** Midnight of a day in the business's timezone, $daysAgo days before today. */
    private function day(int $daysAgo, string $tz = self::TZ): CarbonImmutable
    {
        return $this->now->setTimezone($tz)->startOfDay()->subDays($daysAgo);
    }

    /** A wall-clock time on a day, as a UTC instant. */
    private function localAt(int $daysAgo, int $hour, int $minute = 0, string $tz = self::TZ): CarbonImmutable
    {
        return $this->day($daysAgo, $tz)->setTime($hour, $minute)->utc();
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  array<int|string, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        $items = array_values($items);

        return $items[mt_rand(0, count($items) - 1)];
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, max(1, $total));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_first($weights);
    }

    // ---------------------------------------------------------------------------------------------
    // People
    // ---------------------------------------------------------------------------------------------

    /** The demo's own accounts in sandbox mode; nobody can sign in to them (View as reaches the owners). */
    public const SANDBOX_ACCOUNTS = [
        'admin' => ['Alex Morgan', 'alex.morgan@surehelp-demo.example', 'admin'],
        'john' => ['John Carter', 'john.carter@surehelp-demo.example', 'agent'],
        'mike' => ['Mike Johnson', 'mike.johnson@surehelp-demo.example', 'agent'],
        'owner' => ['Elena Rivera', 'elena@riveraplumbing.example', 'client'],
        'sarah' => ['Dr. Sarah Wilson', 'sarah@brightsmile.example', 'client'],
    ];

    private User $sarah;

    private function users(): void
    {
        if ($this->sandbox) {
            $this->sandboxUsers();

            return;
        }
        $find = fn (string $email) => User::where('email', $email)->firstOrFail();
        $this->admin = $find('admin@surehelpsolution.com');
        $this->john = $find('agent@surehelpsolution.com');
        $this->mike = $find('mike.agent@surehelpsolution.com');
        $this->owner = $find('client@surehelpsolution.com');
        $sarah = $this->sarah = $find('sarah.client@surehelpsolution.com');

        $joined = $this->now->subDays(220);
        $this->admin->forceFill(['name' => 'Alex Morgan', 'phone' => '+1 (512) 555-0100', 'timezone' => self::TZ, 'created_at' => $joined])->save();
        $this->john->forceFill(['name' => 'John Carter', 'phone' => '+1 (512) 555-0101', 'timezone' => self::TZ, 'created_at' => $joined->addDays(20)])->save();
        $this->mike->forceFill(['name' => 'Mike Johnson', 'phone' => '+1 (512) 555-0103', 'timezone' => self::TZ, 'created_at' => $joined->addDays(45)])->save();
        $this->owner->forceFill(['name' => 'Elena Rivera', 'phone' => '+1 (512) 555-0102', 'created_at' => $this->now->subDays(131)])->save();
        $sarah->forceFill(['name' => 'Dr. Sarah Wilson', 'phone' => '+1 (512) 555-0104', 'created_at' => $this->now->subDays(121)])->save();

        $this->travel($this->now->subDays(129));
        $this->manager = $this->newUser('Marcus Rivera', 'marcus@riveraplumbing.example', '+1 (512) 555-0110');
        $this->staff = $this->newUser('Sofia Ramirez', 'sofia@riveraplumbing.example', '+1 (512) 555-0111');
        $this->travel($this->now);
    }

    private function sandboxUsers(): void
    {
        $joined = $this->now->subDays(220);
        $make = function (string $key, string $phone, CarbonImmutable $created, ?string $timezone = null) {
            [$name, $email, $role] = self::SANDBOX_ACCOUNTS[$key];
            $this->travel($created);
            $user = $this->newUser($name, $email, $phone, $role);
            $user->forceFill(['timezone' => $timezone ?? $user->timezone])->save();

            return $user;
        };
        $this->admin = $make('admin', '+1 (512) 555-0100', $joined, self::TZ);
        $this->john = $make('john', '+1 (512) 555-0101', $joined->addDays(20), self::TZ);
        $this->mike = $make('mike', '+1 (512) 555-0103', $joined->addDays(45), self::TZ);
        $this->owner = $make('owner', '+1 (512) 555-0102', $this->now->subDays(131));
        $this->sarah = $make('sarah', '+1 (512) 555-0104', $this->now->subDays(121));

        $this->travel($this->now->subDays(129));
        $this->manager = $this->newUser('Marcus Rivera', 'marcus@riveraplumbing.example', '+1 (512) 555-0110');
        $this->staff = $this->newUser('Sofia Ramirez', 'sofia@riveraplumbing.example', '+1 (512) 555-0111');
        $this->travel($this->now);
    }

    private function newUser(string $name, string $email, ?string $phone = null, string $role = 'client'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            // Sandbox accounts get a password nobody knows: the demo must never open a way in.
            'password' => $this->sandbox ? Str::random(64) : 'password',
            'role' => $role,
            'phone' => $phone,
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    // ---------------------------------------------------------------------------------------------
    // Plans, add-ons and feature access
    // ---------------------------------------------------------------------------------------------

    private function catalogue(): void
    {
        $this->travel($this->now->subDays(200));

        $slug = fn (string $s) => $this->sandbox ? 'demo-'.$s : $s;
        $this->plans['starter'] = Plan::create([
            'slug' => $slug('starter'), 'name' => 'Starter', 'description' => 'Live answering for small businesses: calls, messages and bookings.',
            'price_cents' => 14900, 'interval' => 'month', 'trial_days' => 14, 'sort_order' => 1,
            'features' => ['customer_emails'],
            'limits' => ['calls' => 100, 'extra_call_cents' => 150, 'team_members' => 2, 'calendars' => 1],
        ]);
        $this->plans['receptionist'] = Plan::create([
            'slug' => $slug('receptionist'), 'name' => 'Receptionist', 'description' => 'A full virtual receptionist with calendar sync and the AI messaging assistant.',
            'price_cents' => 29900, 'interval' => 'month', 'trial_days' => 14, 'sort_order' => 2,
            'features' => ['customer_emails', 'calendar_sync', 'ai_assistant'],
            'limits' => ['calls' => 250, 'extra_call_cents' => 125, 'team_members' => 5, 'calendars' => 2],
        ]);
        $this->plans['pro'] = Plan::create([
            'slug' => $slug('pro'), 'name' => 'Pro', 'description' => 'Everything, for busy service businesses: social publishing and website tools included.',
            'price_cents' => 49900, 'interval' => 'month', 'trial_days' => 14, 'sort_order' => 3,
            'features' => ['customer_emails', 'calendar_sync', 'ai_assistant', 'social_publishing', 'website_tools', 'white_label', 'customer_payments'],
            'limits' => ['calls' => 600, 'extra_call_cents' => 100, 'team_members' => 15, 'calendars' => 5],
        ]);

        foreach ([
            ['seo_service', 'SEO service', 'AI-assisted SEO work every month, with Google Search Console statistics.', 19900, 1],
            ['social_publishing', 'Social publishing', 'Plan and publish posts to Facebook, Instagram, LinkedIn and Google.', 4900, 2],
            ['website_tools', 'Website tools', 'Website health check plus chat, booking and click-to-call on your site.', 2900, 3],
            ['bilingual', 'Bilingual answering', 'English and Spanish speaking agents for every call.', 3100, 4],
        ] as [$slug, $name, $description, $price, $sort]) {
            // In sandbox mode a real add-on with this slug is used as it is (feature access goes by slug).
            $this->addons[$slug] = ($this->sandbox ? Addon::query()->where('slug', $slug)->first() : null)
                ?? Addon::create(['slug' => $slug, 'name' => $name, 'description' => $description, 'price_cents' => $price, 'sort_order' => $sort]);
        }
        if ($this->sandbox) {
            // Demo plans aren't offered to anyone, and the real feature settings stay as they are.
            foreach ($this->plans as $plan) {
                $plan->forceFill(['name' => $plan->name.' (demo)', 'is_public' => false])->save();
            }

            return;
        }

        // Paid features really are paid in the demo, so the "Upgrade" badges show for smaller plans.
        app(FeatureAccess::class)->saveModes([
            'calendar_sync' => FeatureAccess::EVERYONE,
            'customer_emails' => FeatureAccess::EVERYONE,
            'ai_assistant' => FeatureAccess::PLAN,
            'social_publishing' => FeatureAccess::PLAN,
            'website_tools' => FeatureAccess::PLAN,
            'seo_service' => FeatureAccess::PLAN,
            'white_label' => FeatureAccess::PLAN,
            'customer_payments' => FeatureAccess::PLAN,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Agent University (courses are published before businesses start, so rules reach agents)
    // ---------------------------------------------------------------------------------------------

    /** @var array<string, TrainingCourse> */
    private array $courses = [];

    private function training(): void
    {
        $this->travel($this->now->subDays(150));
        $titles = ['handling' => 'SureHelp Call Handling Standards', 'privacy' => 'Privacy and Security Essentials'];
        // In sandbox mode the real starter courses, if installed, are used as they are.
        $installed = $this->sandbox && TrainingCourse::query()->whereNull('organization_id')->whereIn('title', $titles)->count() === count($titles);
        if (! $installed) {
            Artisan::call('training:starter');
        }

        $publisher = app(CoursePublisher::class);
        foreach ($titles as $key => $title) {
            $course = TrainingCourse::query()->whereNull('organization_id')->where('title', $title)->firstOrFail();
            if (! $installed) {
                $course->forceFill(['owner_user_id' => $this->admin->id, 'created_by_user_id' => $this->admin->id])->save();
                $publisher->publish($course, $this->admin, 'First release');
            }
            $this->courses[$key] = $course->fresh();
        }

        $this->travel($this->now->subDays(148));
        $course = TrainingCourse::create([
            'title' => 'Handling Difficult Callers', 'summary' => 'Stay calm, take control and turn complaints into bookings.',
            'description' => 'Practical techniques for upset, confused or rushed callers, with real examples from home-service businesses.',
            'difficulty' => 'intermediate', 'estimated_minutes' => 20, 'valid_for_months' => 24,
            'issues_certificate' => true, 'certificate_name' => 'Difficult Callers Certificate', 'category_id' => $this->courses['handling']->category_id,
            'owner_user_id' => $this->admin->id, 'created_by_user_id' => $this->admin->id,
        ]);
        $this->courseContent($course, [
            ['Staying calm', [
                ['Listen first', "## Let them finish\nAn upset caller needs to be heard before they can listen. Let them finish, then **acknowledge the feeling**: \"I'm sorry the technician was late, that's frustrating when you've taken time off work.\"\n\n## Name the next step\nPeople calm down when they know what happens next. Say exactly what you will do and when the business will be in touch.", 6],
                ['Words that help, words that hurt', "Use: *\"Let me make sure I've got this right\"*, *\"Here's what I can do right now\"*.\n\nAvoid: *\"Calm down\"*, *\"That's not my department\"*, *\"There's nothing I can do\"*.", 5],
            ]],
            ['Turning it around', [
                ['From complaint to booking', 'Many complaints end with a return visit. Offer the earliest slot, confirm the address and log the outcome as **Escalated to the business** when the caller asks for a manager.', 5],
            ]],
        ], [
            ['single', 'A caller says the plumber was two hours late. What do you say first?', [['Acknowledge how frustrating that was', true], ['Explain traffic was bad that day', false], ['Ask them to calm down', false]], 'Acknowledge the feeling before anything else.'],
            ['multiple', 'Which of these calm an upset caller?', [['Saying exactly what happens next', true], ['Repeating back the problem', true], ['Putting them on hold to check', false]], 'Clarity and being heard calm people down.'],
            ['true_false', 'If a caller asks for a manager, log the call as "Escalated to the business".', [['True', true], ['False', false]], 'Escalations reach the business owner straight away.'],
        ]);
        $publisher->publish($course, $this->admin, 'First release');
        $this->courses['difficult'] = $course->fresh();

        $path = TrainingPath::create(['title' => 'New agent onboarding', 'description' => 'Everything a new SureHelp agent completes in their first two weeks.', 'created_by_user_id' => $this->admin->id]);
        foreach (['handling', 'privacy', 'difficult'] as $i => $key) {
            DB::table('training_path_courses')->insert(['path_id' => $path->id, 'course_id' => $this->courses[$key]->id, 'position' => $i]);
        }

        $assigner = app(TrainingAssigner::class);
        if ($this->sandbox) {
            // Rules for the demo agents only: real agents get no demo training.
            foreach ([$this->john, $this->mike] as $agent) {
                $assigner->createRule($this->courses['handling'], $this->admin, ['scope' => 'agent', 'agent_user_id' => $agent->id, 'is_required' => true, 'priority' => 'high', 'due_days' => 14, 'enforcement' => 'warning']);
                $assigner->createRule($this->courses['privacy'], $this->admin, ['scope' => 'agent', 'agent_user_id' => $agent->id, 'is_required' => true, 'priority' => 'normal', 'due_days' => 30, 'enforcement' => 'warning']);
            }
        } else {
            $assigner->createRule($this->courses['handling'], $this->admin, ['scope' => 'everyone', 'is_required' => true, 'priority' => 'high', 'due_days' => 14, 'enforcement' => 'warning']);
            $assigner->createRule($this->courses['privacy'], $this->admin, ['scope' => 'role', 'role' => 'agent', 'is_required' => true, 'priority' => 'normal', 'due_days' => 30, 'enforcement' => 'warning']);
        }

        // John completes everything early; Mike finishes the standards, the rest is in progress.
        $this->travel($this->now->subDays(146)->setTime(15, 10));
        $this->takeCourse($this->john, $this->courses['handling']);
        $this->travel($this->now->subDays(140)->setTime(16, 20));
        $this->takeCourse($this->john, $this->courses['privacy']);
        $this->travel($this->now->subDays(138)->setTime(14, 40));
        $this->takeCourse($this->john, $this->courses['difficult']);
        $this->travel($this->now->subDays(144)->setTime(17, 5));
        $this->takeCourse($this->mike, $this->courses['handling']);
        $this->travel($this->now->subDays(3)->setTime(19, 30));
        $this->takeCourse($this->mike, $this->courses['privacy'], 1);
    }

    /**
     * @param  list<array{0: string, 1: list<array{0: string, 1: string, 2: int}>}>  $modules
     * @param  list<array{0: string, 1: string, 2: list<array{0: string, 1: bool}>, 3: string}>  $questions
     */
    private function courseContent(TrainingCourse $course, array $modules, array $questions): void
    {
        $last = null;
        foreach ($modules as $m => [$title, $lessons]) {
            $module = TrainingModule::create(['course_id' => $course->id, 'title' => $title, 'position' => $m]);
            foreach ($lessons as $l => [$lessonTitle, $body, $minutes]) {
                TrainingLesson::create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => $lessonTitle, 'type' => 'text', 'body' => $body, 'duration_minutes' => $minutes, 'position' => $l]);
            }
            $last = $module;
        }
        $quiz = TrainingLesson::create(['course_id' => $course->id, 'module_id' => $last?->id, 'title' => 'Check your understanding', 'type' => 'quiz', 'pass_percent' => 80, 'max_attempts' => 3, 'position' => 99]);
        foreach ($questions as $i => [$type, $prompt, $options, $explanation]) {
            TrainingQuestion::create([
                'lesson_id' => $quiz->id, 'type' => $type, 'prompt' => $prompt, 'explanation' => $explanation, 'position' => $i,
                'options' => array_map(fn (array $o) => ['id' => TrainingQuestion::optionId(), 'label' => $o[0], 'correct' => $o[1]], $options),
            ]);
        }
    }

    /** Takes a course as the agent, answering every quiz correctly; $lessons limits how far they get. */
    private function takeCourse(User $agent, TrainingCourse $course, ?int $lessons = null): void
    {
        $learning = app(LearningProgress::class);
        $assignment = $learning->enrol($agent, $course);
        $version = $learning->begin($assignment);
        $done = 0;
        foreach ($version->lessons() as $lesson) {
            if ($lessons !== null && $done >= $lessons) {
                break;
            }
            $this->travel($this->clock->addMinutes(mt_rand(4, 9)));
            if ($lesson['type'] === 'quiz') {
                $attempt = $learning->startAttempt($assignment->fresh(), $version, $lesson['ulid']);
                $this->travel($this->clock->addMinutes(mt_rand(3, 6)));
                $answers = [];
                foreach ($lesson['quiz']['questions'] as $question) {
                    $correct = array_column(array_values(array_filter($question['options'], fn (array $o) => $o['correct'])), 'id');
                    $answers[$question['ulid']] = QuestionType::from($question['type'])->allowsMany() ? $correct : $correct[0];
                }
                $learning->submitAttempt($attempt, $version, $answers);
            } else {
                $learning->completeLesson($assignment->fresh(), $version, $lesson['ulid'], mt_rand(200, 600));
            }
            $done++;
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Businesses
    // ---------------------------------------------------------------------------------------------

    private function businesses(): void
    {
        $this->specs = [
            'rivera' => [
                'name' => 'Rivera Plumbing & Heating', 'owner' => $this->owner, 'start' => 130, 'industry' => 'plumbing', 'mode' => 'address',
                'phone' => '(512) 555-0147', 'email' => 'hello@riveraplumbing.example', 'website' => 'https://riveraplumbing.example',
                'job_value' => 38500, 'plan' => 'pro', 'agents' => [[$this->john, 'standard', true], [$this->mike, 'standard', false]],
                'calls' => 220, 'call_days' => 120, 'pool' => 80, 'pool_offset' => 0,
            ],
            'dental' => [
                'name' => 'Bright Smile Dental', 'owner' => $this->sarah, 'start' => 120, 'industry' => 'dental', 'mode' => 'business',
                'phone' => '(512) 555-0161', 'email' => 'frontdesk@brightsmile.example', 'website' => 'https://brightsmile.example',
                'job_value' => 22000, 'plan' => 'receptionist', 'agents' => [[$this->john, 'standard', true], [$this->mike, 'backup', false]],
                'calls' => 55, 'call_days' => 115, 'pool' => 32, 'pool_offset' => 17,
            ],
            'law' => [
                'name' => 'Lakeside Law Group', 'owner' => null, 'owner_name' => 'David Chen', 'owner_email' => 'david@lakesidelaw.example', 'start' => 92, 'industry' => 'legal', 'mode' => 'business',
                'phone' => '(512) 555-0172', 'email' => 'intake@lakesidelaw.example', 'website' => 'https://lakesidelaw.example',
                'job_value' => 150000, 'plan' => 'starter', 'agents' => [[$this->john, 'standard', true]],
                'calls' => 30, 'call_days' => 88, 'pool' => 18, 'pool_offset' => 33,
            ],
            'hvac' => [
                'name' => 'Green Valley HVAC', 'owner' => null, 'owner_name' => 'Tom Becker', 'owner_email' => 'tom@greenvalleyhvac.example', 'start' => 7, 'industry' => 'hvac', 'mode' => 'address',
                'phone' => '(512) 555-0183', 'email' => 'service@greenvalleyhvac.example', 'website' => 'https://greenvalleyhvac.example',
                'job_value' => 41000, 'plan' => 'receptionist', 'trial' => true, 'onboarding' => true, 'agents' => [[$this->mike, 'standard', true]],
                'calls' => 16, 'call_days' => 6, 'pool' => 14, 'pool_offset' => 49,
            ],
            'yoga' => [
                'name' => 'Sunrise Yoga Studio', 'owner' => null, 'owner_name' => 'Priya Patel', 'owner_email' => 'priya@sunriseyoga.example', 'start' => 104, 'industry' => 'other', 'mode' => 'business',
                'phone' => '(512) 555-0194', 'email' => 'namaste@sunriseyoga.example', 'website' => 'https://sunriseyoga.example',
                'job_value' => 6000, 'plan' => 'starter', 'agents' => [[$this->mike, 'standard', true]],
                'calls' => 26, 'call_days' => 100, 'call_until' => 11, 'pool' => 15, 'pool_offset' => 61,
            ],
        ];

        foreach ($this->specs as $key => $spec) {
            $this->travel($this->localAt($spec['start'], 10, 15));
            $owner = $spec['owner'] ?? $this->newUser($spec['owner_name'], $spec['owner_email'], $spec['phone']);
            $owner->forceFill(['created_at' => now(), 'email_verified_at' => now()])->save();
            $this->specs[$key]['owner'] = $owner;

            $organization = app(ProvisionUserTenancy::class)->handle($owner);
            $organization->forceFill([
                'name' => $spec['name'],
                'slug' => Organization::uniqueSlug($spec['name']),
                'timezone' => self::TZ,
                'status' => ! empty($spec['onboarding']) ? OrganizationStatus::Onboarding : OrganizationStatus::Active,
                'average_job_value_cents' => $spec['job_value'],
                'created_at' => now(),
            ])->save();
            $this->orgs[$key] = $organization;
            $this->pool[$key] = $this->people($key, $spec['pool'], $spec['pool_offset']);
            $this->known[$key] = [];

            foreach ($spec['agents'] as [$agent, $type, $primary]) {
                $assignment = app(AssignAgent::class)->handle($organization, $agent, $this->admin, ['type' => $type, 'notes' => $primary ? 'Primary agent for this company.' : null]);
                $assignment->forceFill(['is_primary' => $primary])->save();
            }
        }
        $this->rivera = $this->orgs['rivera'];

        // Company-specific training for Rivera, required for its agents.
        $this->travel($this->localAt(128, 11));
        $course = TrainingCourse::create([
            'organization_id' => $this->rivera->id, 'title' => 'Rivera Plumbing: Call Script & Pricing',
            'summary' => 'How Rivera wants calls answered, what we quote and when to wake the on-call tech.',
            'description' => 'Company-specific training for agents answering Rivera Plumbing & Heating.',
            'difficulty' => 'beginner', 'estimated_minutes' => 15, 'owner_user_id' => $this->admin->id, 'created_by_user_id' => $this->admin->id,
            'issues_certificate' => false, 'category_id' => $this->courses['handling']->category_id,
        ]);
        $this->courseContent($course, [
            ['Answering for Rivera', [
                ['Greeting and tone', "Answer: **\"Thank you for calling Rivera Plumbing & Heating, this is {your name}, how can I help?\"**\n\nRivera is a family business: warm, plain words, no jargon.", 4],
                ['Prices we can quote', "- Diagnostic visit: **\$79**, credited if the work goes ahead\n- Drain cleaning: **\$149**\n- Emergency call-out (nights, weekends): **\$249**\n\nEverything else is quoted on site. Never promise a price for a water heater replacement on the phone.", 5],
                ['Emergencies', 'Burst pipe, sewage backup or no water at all: walk the caller through shutting the main valve, then log the call as an emergency. Gas smell: tell them to leave the house and call Texas Gas Service or 911 first.', 5],
            ]],
        ], [
            ['single', 'What does a diagnostic visit cost?', [['$79, credited if the work goes ahead', true], ['Free', false], ['$149', false]], 'The $79 is credited against the repair.'],
            ['true_false', 'You may quote a price for a new water heater on the phone.', [['True', false], ['False', true]], 'Replacements are always quoted on site.'],
        ]);
        app(CoursePublisher::class)->publish($course, $this->admin, 'First release');
        $this->courses['rivera'] = $course->fresh();
        app(TrainingAssigner::class)->createRule($this->courses['rivera'], $this->admin, ['scope' => 'company', 'organization_id' => $this->rivera->id, 'is_required' => true, 'priority' => 'high', 'due_days' => 21, 'enforcement' => 'warning']);
        $this->travel($this->localAt(126, 9, 30));
        $this->takeCourse($this->john, $this->courses['rivera']);
    }

    /**
     * A business's customers-to-be: Austin-area names, numbers and addresses.
     *
     * @return list<array<string, mixed>>
     */
    private function people(string $key, int $count, int $offset): array
    {
        $first = ['Maria', 'James', 'Linda', 'Robert', 'Patricia', 'Michael', 'Jennifer', 'David', 'Elizabeth', 'Carlos', 'Susan', 'Daniel', 'Jessica', 'Matthew', 'Karen', 'Anthony', 'Nancy', 'Mark', 'Lisa', 'Steven',
            'Ashley', 'Andrew', 'Kimberly', 'Joshua', 'Emily', 'Kevin', 'Donna', 'Brian', 'Michelle', 'Jorge', 'Laura', 'Ryan', 'Rebecca', 'Jacob', 'Sharon', 'Gary', 'Cynthia', 'Eric', 'Kathleen', 'Jonathan',
            'Amy', 'Stephen', 'Angela', 'Larry', 'Melissa', 'Justin', 'Brenda', 'Scott', 'Nicole', 'Brandon', 'Rosa', 'Benjamin', 'Samantha', 'Luis', 'Katherine', 'Frank', 'Christine', 'Raymond', 'Debra', 'Alejandro',
            'Rachel', 'Gregory', 'Carolyn', 'Samuel', 'Janet', 'Patrick', 'Catherine', 'Jack', 'Heather', 'Dennis', 'Diane', 'Jerry', 'Olivia', 'Tyler', 'Julie', 'Aaron', 'Joyce', 'Jose', 'Victoria', 'Adam',
            'Kelly', 'Nathan', 'Christina', 'Henry', 'Lauren', 'Zachary', 'Joan', 'Douglas', 'Evelyn', 'Peter', 'Olga', 'Kyle', 'Judith', 'Noah', 'Megan', 'Ethan', 'Andrea', 'Jeremy', 'Cheryl', 'Walter'];
        $last = ['Garcia', 'Smith', 'Johnson', 'Martinez', 'Williams', 'Brown', 'Hernandez', 'Davis', 'Lopez', 'Miller', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin', 'Lee', 'Perez',
            'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson', 'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores', 'Green', 'Adams',
            'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell', 'Carter', 'Roberts', 'Gomez', 'Phillips', 'Evans', 'Turner', 'Diaz', 'Parker', 'Cruz', 'Edwards', 'Collins', 'Reyes', 'Stewart', 'Morris',
            'Morales', 'Murphy', 'Cook', 'Rogers', 'Gutierrez', 'Ortiz', 'Morgan', 'Cooper', 'Peterson', 'Bailey', 'Reed', 'Kelly', 'Howard', 'Ramos', 'Kim', 'Cox', 'Ward', 'Richardson', 'Watson', 'Brooks',
            'Chavez', 'Wood', 'James', 'Bennett', 'Gray', 'Mendoza', 'Ruiz', 'Hughes', 'Price', 'Alvarez', 'Castillo', 'Sanders', 'Patel', 'Myers', 'Long', 'Ross', 'Foster', 'Jimenez', 'Powell', 'Jenkins'];
        $places = [
            ['Austin', '78704', ['Barton Hills Dr', 'S 1st St', 'Kinney Ave', 'Bouldin Ave', 'Oltorf St']],
            ['Austin', '78745', ['Manchaca Rd', 'Westgate Blvd', 'Cherry Creek Dr', 'Emerald Forest Dr']],
            ['Austin', '78702', ['E Cesar Chavez St', 'Holly St', 'Chicon St', 'E 12th St']],
            ['Austin', '78731', ['Balcones Dr', 'Far West Blvd', 'Mount Bonnell Rd', 'Greystone Dr']],
            ['Austin', '78749', ['Brodie Ln', 'Escarpment Blvd', 'Convict Hill Rd', 'Slaughter Ln']],
            ['Austin', '78757', ['Burnet Rd', 'Shoal Creek Blvd', 'Allandale Rd', 'W Anderson Ln']],
            ['Austin', '78723', ['Berkman Dr', 'Manor Rd', 'Philomena St', 'Aldrich St']],
            ['Austin', '78759', ['Spicewood Springs Rd', 'Great Hills Trl', 'Jollyville Rd', 'Mesa Dr']],
            ['Round Rock', '78664', ['Gattis School Rd', 'Red Bud Ln', 'Sam Bass Rd', 'Palm Valley Blvd']],
            ['Round Rock', '78681', ['Creek Bend Blvd', 'Brushy Creek Rd', 'Wyoming Springs Dr', 'Hairy Man Rd']],
            ['Pflugerville', '78660', ['Pecan St', 'Kelly Ln', 'Swenson Farms Blvd', 'Pfennig Ln']],
            ['Cedar Park', '78613', ['Whitestone Blvd', 'Cypress Creek Rd', 'Buttercup Creek Blvd', 'Anderson Mill Rd']],
            ['Georgetown', '78626', ['S Austin Ave', 'Williams Dr', 'Shell Rd', 'Leander Rd']],
            ['Leander', '78641', ['Crystal Falls Pkwy', 'Hero Way', 'Bagdad Rd']],
            ['Buda', '78610', ['Main St', 'Cabelas Dr', 'Old Black Colony Rd']],
        ];
        $companies = [
            'rivera' => ['Hill Country Property Management', 'South Congress Cafe', 'Oak Hill Apartments', 'Lakeline Dental Office', 'Barton Creek Fitness', 'Mueller Montessori School'],
            'dental' => ['Austin ISD Benefits Office'],
            'law' => ['Blue Bonnet Bakery LLC', 'Pecan Street Ventures', 'Cedar Ridge Builders'],
            'hvac' => ['Round Rock Self Storage'],
            'yoga' => ['Dell Wellness Program'],
        ][$key] ?? [];
        $domains = ['example.com', 'example.net', 'example.org', 'mail.example'];

        $list = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $offset + $i;
            $f = $first[($n * 7) % count($first)];
            $l = $last[($n * 13 + 5) % count($last)];
            [$city, $zip, $streets] = $places[($n * 5 + 3) % count($places)];
            $area = $n % 3 === 0 ? '737' : '512';
            $phone = sprintf('(%s) 555-%04d', $area, 100 + $n % 100 + intdiv($n, 100) * 100);
            $company = $companies[$i] ?? null;
            $list[] = [
                'first' => $f,
                'last' => $l,
                'name' => "{$f} {$l}",
                'phone' => $phone,
                'email' => $i % 7 === 3 ? null : Str::lower($f.'.'.$l).'@'.$domains[$n % count($domains)],
                'company' => $company,
                'line1' => (100 + ($n * 389) % 9800).' '.$streets[$n % count($streets)],
                'line2' => $n % 9 === 4 ? 'Apt '.(100 + $n % 300) : null,
                'city' => $city,
                'state' => 'TX',
                'zip' => $zip,
                'type' => $company ? 'commercial' : 'residential',
            ];
        }
        // Commercial customers come first in the list; mix them in.
        $commercial = array_slice($list, 0, count($companies));
        $rest = array_slice($list, count($companies));
        foreach ($commercial as $j => $c) {
            array_splice($rest, min(count($rest), 5 + $j * 9), 0, [$c]);
        }

        return $rest;
    }

    /** Creates a business's profile, places, hours, services, knowledge and rules. */
    private function setUpBusiness(string $key, array $profile, array $locations, array $hours, array $services): void
    {
        $organization = $this->orgs[$key];
        $spec = $this->specs[$key];
        $owner = $spec['owner'];

        BusinessProfile::create(['organization_id' => $organization->id, 'display_name' => $spec['name'], 'phone' => $spec['phone'], 'email' => $spec['email'], 'website' => $spec['website']] + $profile);

        foreach ($locations as $i => $location) {
            $this->locations[$key][$location['key']] = BusinessLocation::create([
                'organization_id' => $organization->id, 'name' => $location['name'], 'address_line1' => $location['line1'], 'address_line2' => $location['line2'] ?? null,
                'city' => $location['city'], 'state' => 'TX', 'postal_code' => $location['zip'], 'country' => 'US', 'phone' => $location['phone'] ?? $spec['phone'], 'is_primary' => $i === 0,
            ]);
        }

        foreach ($hours as $day => $spans) {
            foreach ($spans as [$open, $close]) {
                BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => $open, 'closes_at' => $close]);
            }
        }

        foreach ($services as $i => $service) {
            $this->services[$key][$service['name']] = BusinessService::create([
                'organization_id' => $organization->id,
                'name' => $service['name'],
                'description' => $service['description'] ?? null,
                'category' => $service['category'] ?? null,
                'duration_minutes' => $service['minutes'],
                'buffer_minutes' => $service['buffer'] ?? 0,
                'price_type' => $service['type'],
                'price_cents' => $service['price'] ?? null,
                'is_bookable' => $service['bookable'] ?? true,
                'required_fields' => $service['required'] ?? null,
                'agent_instructions' => $service['instructions'] ?? null,
                'sort_order' => $i + 1,
            ]);
        }

        $organization->forceFill([
            'setup_progress' => ! empty($spec['onboarding'])
                ? ['business' => 'done', 'services' => 'done', 'hours' => 'done']
                : ['business' => 'done', 'services' => 'done', 'hours' => 'done', 'calls' => 'done', 'calendar' => $key === 'rivera' ? 'done' : 'skipped', 'team' => $key === 'rivera' ? 'done' : 'skipped', 'review' => 'done'],
            'setup_completed_at' => ! empty($spec['onboarding']) ? null : now()->addHours(26),
        ])->save();
        $this->busy[$key] = [];

        // The plan: no trial for established customers (they converted long ago); a trial for the newest.
        $this->travel($this->clock->addDays(! empty($spec['trial']) ? 0 : 2)->setTime(14, 0));
        app(ManageSubscription::class)->subscribe($organization->fresh(), $this->plans[$spec['plan']], $this->admin, CarbonImmutable::today(), ! empty($spec['trial']));
        $this->summary[] = "{$spec['name']}: {$this->plans[$spec['plan']]->name} plan".(! empty($spec['trial']) ? ' (trial)' : '');
        unset($owner);
    }

    private function rivera(): void
    {
        $this->travel($this->localAt(130, 10, 40));
        $org = $this->rivera;
        $org->members()->attach($this->manager->id, ['role' => 'manager', 'status' => 'active', 'invited_at' => now(), 'joined_at' => now()->addHours(3)]);
        $org->members()->attach($this->staff->id, ['role' => 'staff', 'status' => 'active', 'invited_at' => now(), 'joined_at' => now()->addHours(5)]);

        $this->setUpBusiness('rivera', [
            'legal_name' => 'Rivera Plumbing & Heating LLC',
            'business_type' => 'Residential and commercial plumbing',
            'industry' => 'plumbing',
            'description' => 'Family-owned plumbing and heating company serving Austin and Williamson County since 2009. Licensed master plumbers (TX M-41872), upfront pricing and 24/7 emergency service.',
            'service_area' => 'Austin, Round Rock, Pflugerville, Cedar Park, Georgetown, Leander, Lakeway and Buda',
            'emergency_available' => true,
            'emergency_instructions' => 'Burst pipes, sewage backups, no water and active leaks: talk the caller through shutting off the main valve, then escalate to the on-call technician (Marcus, (512) 555-0110). Gas smell: tell them to leave the house and call Texas Gas Service at 1-800-959-5325 or 911 first.',
            'social_approval' => 'owner',
        ], [
            ['key' => 'austin', 'name' => 'Austin (main shop)', 'line1' => '2417 S Lamar Blvd', 'line2' => 'Suite B', 'city' => 'Austin', 'zip' => '78704'],
            ['key' => 'roundrock', 'name' => 'Round Rock branch', 'line1' => '1600 N Mays St', 'city' => 'Round Rock', 'zip' => '78664', 'phone' => '(512) 555-0148'],
        ], [
            1 => [['07:00', '18:00']], 2 => [['07:00', '18:00']], 3 => [['07:00', '18:00']], 4 => [['07:00', '18:00']], 5 => [['07:00', '18:00']], 6 => [['08:00', '14:00']],
        ], [
            ['name' => 'Drain Cleaning', 'category' => 'Drains & sewer', 'minutes' => 60, 'buffer' => 15, 'type' => 'fixed', 'price' => 14900, 'description' => 'Clear slow or blocked sink, tub, shower and floor drains with a professional cable machine.', 'required' => ['address', 'phone'], 'instructions' => 'Ask which drain, how long it has been slow and whether they used chemical cleaners.'],
            ['name' => 'Water Heater Repair', 'category' => 'Water heaters', 'minutes' => 90, 'buffer' => 15, 'type' => 'starting_from', 'price' => 18900, 'description' => 'Diagnose and repair gas, electric and tankless water heaters: pilot, thermostat, elements, valves.', 'required' => ['address', 'phone', 'details'], 'instructions' => 'Ask for the brand, age and whether it is gas or electric.'],
            ['name' => 'Water Heater Installation', 'category' => 'Water heaters', 'minutes' => 240, 'buffer' => 30, 'type' => 'starting_from', 'price' => 145000, 'description' => 'Supply and install a new 40/50-gallon tank or tankless unit, including permit and haul-away.', 'required' => ['address', 'phone'], 'instructions' => 'Do not quote a final price; the technician confirms on site.'],
            ['name' => 'Leak Detection & Repair', 'category' => 'Leaks', 'minutes' => 90, 'buffer' => 15, 'type' => 'starting_from', 'price' => 17500, 'description' => 'Find and fix leaks in supply lines, fittings and slab using acoustic and thermal tools.', 'required' => ['address', 'phone', 'details']],
            ['name' => 'Emergency Call-Out (24/7)', 'category' => 'Emergency', 'minutes' => 60, 'type' => 'fixed', 'price' => 24900, 'description' => 'Nights, weekends and holidays: a technician on site within 90 minutes for burst pipes, backups and floods.', 'required' => ['address', 'phone'], 'instructions' => 'Escalate every emergency to the on-call technician as well as booking.'],
            ['name' => 'Toilet Repair', 'category' => 'Fixtures', 'minutes' => 60, 'type' => 'fixed', 'price' => 12900, 'description' => 'Running, leaking or clogged toilets: flappers, fill valves, wax rings and seals.'],
            ['name' => 'Faucet & Fixture Installation', 'category' => 'Fixtures', 'minutes' => 60, 'type' => 'starting_from', 'price' => 11900, 'description' => 'Install customer-supplied or Rivera-supplied faucets, sinks, garbage disposals and shower valves.'],
            ['name' => 'Sewer Line Camera Inspection', 'category' => 'Drains & sewer', 'minutes' => 90, 'buffer' => 15, 'type' => 'fixed', 'price' => 29900, 'description' => 'HD camera inspection of the main sewer line with a recorded video and written report.', 'required' => ['address', 'phone']],
            ['name' => 'Heating Tune-Up', 'category' => 'Heating', 'minutes' => 60, 'type' => 'fixed', 'price' => 9900, 'description' => 'Seasonal furnace safety check and tune-up: burners, ignition, flue, filter and carbon-monoxide test.'],
            ['name' => 'Remodel & Repipe Estimate', 'category' => 'Estimates', 'minutes' => 60, 'type' => 'quote_required', 'description' => 'Free on-site estimate for bathroom and kitchen remodels, whole-house repipes and water softeners.', 'instructions' => 'Free. Ask for photos by email if the customer can send them.'],
            ['name' => 'Gas Line Repair', 'category' => 'Gas', 'minutes' => 120, 'type' => 'quote_required', 'bookable' => false, 'description' => 'Gas leak repair and new gas lines for ranges, dryers and grills. Quote after inspection.', 'instructions' => 'Not bookable by phone: escalate to the business. Gas smell = leave the house first.'],
        ]);

        BusinessHoliday::create(['organization_id' => $org->id, 'date' => '2026-09-07', 'name' => 'Labor Day', 'is_closed' => true]);
        BusinessHoliday::create(['organization_id' => $org->id, 'date' => '2026-11-26', 'name' => 'Thanksgiving Day', 'is_closed' => true]);
        BusinessHoliday::create(['organization_id' => $org->id, 'date' => '2026-12-24', 'name' => 'Christmas Eve (short day)', 'is_closed' => false, 'opens_at' => '08:00', 'closes_at' => '12:00']);
        BusinessHoliday::create(['organization_id' => $org->id, 'date' => '2026-12-25', 'name' => 'Christmas Day', 'is_closed' => true]);

        CallOutcome::create(['organization_id' => $org->id, 'key' => 'estimate-requested', 'label' => 'Estimate request taken', 'category' => 'information', 'sort_order' => 25]);

        $knowledge = [
            ['faq', 'Do you charge for estimates?', 'Estimates for remodels, repipes, water heater replacements and water softeners are free. Repairs start with a $79 diagnostic visit, which is credited against the repair if the customer goes ahead.', 'Pricing', true, 'public'],
            ['faq', 'Are you licensed and insured?', 'Yes. Rivera Plumbing & Heating is fully insured and every technician works under master plumber license TX M-41872.', 'Company', false, 'public'],
            ['faq', 'Which areas do you serve?', 'Austin, Round Rock, Pflugerville, Cedar Park, Georgetown, Leander, Lakeway and Buda. Outside these areas, take a message for the owner.', 'Service area', false, 'public'],
            ['faq', 'Do you work weekends?', 'Regular appointments Monday to Friday 7 AM–6 PM and Saturday 8 AM–2 PM. Emergencies are covered 24/7 with the $249 emergency call-out.', 'Hours', false, 'public'],
            ['faq', 'Which payment methods do you accept?', 'All major cards, ACH, Zelle and checks. Financing is available for jobs over $1,500 through Synchrony (subject to approval).', 'Billing', false, 'public'],
            ['policy', 'Cancellation policy', 'Customers can cancel or reschedule free of charge up to 24 hours before the visit. Later cancellations may be charged the $79 diagnostic fee — never promise a waiver, take a message for Elena.', 'Bookings', false, 'internal'],
            ['pricing_guidance', 'What you can quote on the phone', "Diagnostic: \$79 (credited). Drain cleaning: \$149. Toilet repair: \$129. Heating tune-up: \$99. Emergency call-out: \$249.\nEverything else: 'the technician gives you a written price before starting any work.'", 'Pricing', true, 'internal'],
            ['service_info', 'Water heater brands we service', 'We install Bradford White and Rheem tanks and Rinnai and Navien tankless units. We repair all major brands. Tankless descaling is recommended every 12 months.', 'Water heaters', false, 'public'],
            ['procedure', 'Booking a visit', "1. Confirm the address and a mobile number for the technician's text.\n2. Ask about pets and gate codes.\n3. Offer the first available slot at the branch closest to the customer (Round Rock for Williamson County).", 'Bookings', false, 'internal'],
            ['emergency_instruction', 'Gas smell', 'Tell the caller to leave the building immediately, not to use switches or phones inside, and to call Texas Gas Service (1-800-959-5325) or 911. Then escalate to the owner as an emergency.', 'Safety', true, 'internal'],
            ['emergency_instruction', 'Flooding or burst pipe', 'Walk the caller through closing the main shut-off valve (usually in the front yard box near the curb or in the garage). Book the emergency call-out and escalate to the on-call technician.', 'Safety', true, 'internal'],
            ['agent_instruction', 'Greeting', '"Thank you for calling Rivera Plumbing & Heating, this is {your name}, how can I help?" Warm and plain; Rivera is a family business.', 'Script', false, 'team_only'],
            ['escalation_rule', 'When to escalate to Elena', 'Complaints about a technician, refund requests, discounts above 10%, and any commercial contract enquiry go to Elena Rivera the same day.', 'Escalations', false, 'internal'],
        ];
        foreach ($knowledge as $i => [$type, $title, $content, $category, $pinned, $visibility]) {
            KnowledgeItem::create([
                'organization_id' => $org->id, 'type' => $type, 'title' => $title, 'content' => $content, 'category' => $category,
                'visibility' => $visibility, 'is_pinned' => $pinned, 'sort_order' => $i, 'created_by_user_id' => $this->owner->id,
                'service_id' => str_contains($title, 'Water heater') ? $this->services['rivera']['Water Heater Repair']->id : null,
            ]);
        }

        $rules = [
            ['instruction', ['text' => 'Always ask whether there are pets at home and whether there is a gate code.']],
            ['booking_window', ['min_notice_minutes' => 120, 'max_days_ahead' => 45]],
            ['service_area', ['postal_codes' => ['78701', '78702', '78703', '78704', '78723', '78731', '78745', '78749', '78757', '78759', '78664', '78681', '78660', '78613', '78626', '78641', '78610', '78734']]],
            ['require_detail', ['field' => 'address']],
            ['auto_escalate', ['reasons' => ['emergency-service'], 'escalation_type' => 'emergency', 'priority' => 'urgent']],
        ];
        foreach ($rules as [$type, $config]) {
            BusinessRule::create(['organization_id' => $org->id, 'type' => $type, 'config' => $config, 'created_by_user_id' => $this->owner->id]);
        }

        MessageTemplate::create([
            'organization_id' => $org->id, 'key' => 'appointment_reminder', 'lead_hours' => 24,
            'subject' => 'Reminder: Rivera Plumbing visits you {date} at {time}',
            'body' => "Hi {first_name},\n\nThis is a friendly reminder that your Rivera Plumbing technician will arrive on {date} at {time} for {service}.\nAddress: {address}\n\nPlease make sure we can reach the water heater or the area to be repaired. Need to change the time? Call us on {phone}.\n\nThanks,\nThe Rivera Plumbing & Heating team",
        ]);

        Tag::create(['organization_id' => $org->id, 'name' => 'VIP', 'color' => 'warning']);
        foreach (['Repeat customer', 'Commercial', 'Rental property', 'Service plan member', 'Warranty', 'Senior discount'] as $name) {
            Tag::create(['organization_id' => $org->id, 'name' => $name]);
        }

        // A few clients came over from the old spreadsheet before the service started.
        foreach (array_splice($this->pool['rivera'], 0, 8) as $person) {
            $customer = Customer::create([
                'organization_id' => $org->id, 'first_name' => $person['first'], 'last_name' => $person['last'], 'company' => $person['company'],
                'phone' => $person['phone'], 'email' => $person['email'], 'address_line1' => $person['line1'], 'address_line2' => $person['line2'],
                'city' => $person['city'], 'state' => 'TX', 'postal_code' => $person['zip'], 'status' => 'customer', 'source' => 'import',
                'created_by_user_id' => $this->owner->id, 'notes' => 'Imported from the old customer spreadsheet.',
            ]);
            $person['customer_id'] = $customer->id;
            $this->known['rivera'][$person['phone']] = $person;
        }

        // Automations: a review request after every finished job, a rebooking task after cancellations, a lead alert.
        $this->travel($this->localAt(128, 16));
        Automation::create(['organization_id' => $org->id, 'name' => 'Ask for a Google review', 'trigger' => 'appointment_completed', 'delay_minutes' => 120, 'action' => 'send_email', 'created_by_user_id' => $this->owner->id,
            'action_config' => ['subject' => 'How did we do, {first_name}?', 'body' => "Hi {first_name},\n\nThanks for choosing {business} for your {service}. If you were happy with the visit, a quick Google review helps our small team a lot:\n{review_link}\n\nThank you!\nElena Rivera", 'consent_only' => true, 'review_link' => 'https://g.page/r/rivera-plumbing-demo/review']]);
        Automation::create(['organization_id' => $org->id, 'name' => 'Call to rebook cancelled jobs', 'trigger' => 'appointment_cancelled', 'delay_minutes' => 60, 'action' => 'create_task', 'created_by_user_id' => $this->owner->id,
            'action_config' => ['title' => 'Call {first_name} to rebook', 'due_hours' => 24]]);
        Automation::create(['organization_id' => $org->id, 'name' => 'Tell the team about new leads', 'trigger' => 'customer_created', 'delay_minutes' => 0, 'action' => 'notify_team', 'created_by_user_id' => $this->owner->id,
            'action_config' => ['message' => 'New lead: {first_name} — check the call notes and follow up today.'], 'is_active' => false]);

        // The website chat and AI assistant.
        $widget = ChatWidget::for($org);
        $widget->update(['title' => 'Chat with Rivera Plumbing', 'greeting' => 'Hi! Need a plumber? Ask us anything or book a visit here.', 'color' => '#0E7490',
            'allowed_origins' => ['https://riveraplumbing.example'], 'features' => ['chat' => true, 'booking' => true, 'call' => true, 'lead' => true], 'bookings_need_confirmation' => false]);
        AiAssistant::create(['organization_id' => $org->id, 'is_enabled' => true, 'name' => 'Rosie', 'tone' => 'friendly',
            'modes' => ['web_chat' => 'auto', 'facebook' => 'suggest', 'instagram' => 'auto'], 'can_book' => true, 'bookings_need_confirmation' => false,
            'handover_message' => "Thanks! I've passed this to the Rivera team — someone will reply here shortly.",
            'instructions' => 'Never quote prices for replacements. For emergencies, give the shut-off advice and hand over straight away.']);
    }

    private function otherBusinesses(): void
    {
        $this->travel($this->localAt(120, 10, 30));
        $this->setUpBusiness('dental', [
            'legal_name' => 'Bright Smile Dental PLLC', 'business_type' => 'Family dentistry', 'industry' => 'dental',
            'description' => 'Gentle family and cosmetic dentistry in Mueller. New patients welcome; most PPO plans accepted.',
            'service_area' => 'Austin (Mueller, Windsor Park, Hyde Park)', 'emergency_available' => true,
            'emergency_instructions' => 'Severe pain, swelling or a knocked-out tooth: offer the first emergency slot today. Swelling with fever or trouble breathing: tell them to go to the ER.',
        ], [
            ['key' => 'mueller', 'name' => 'Mueller office', 'line1' => '1900 Aldrich St', 'line2' => 'Suite 140', 'city' => 'Austin', 'zip' => '78723'],
        ], [
            1 => [['08:00', '17:00']], 2 => [['08:00', '17:00']], 3 => [['08:00', '17:00']], 4 => [['08:00', '17:00']], 5 => [['08:00', '14:00']],
        ], [
            ['name' => 'New Patient Exam & X-rays', 'category' => 'Preventive', 'minutes' => 60, 'type' => 'fixed', 'price' => 12900],
            ['name' => 'Cleaning & Check-up', 'category' => 'Preventive', 'minutes' => 60, 'type' => 'fixed', 'price' => 11000],
            ['name' => 'Emergency Dental Visit', 'category' => 'Emergency', 'minutes' => 45, 'type' => 'fixed', 'price' => 15000],
            ['name' => 'Whitening Consultation', 'category' => 'Cosmetic', 'minutes' => 30, 'type' => 'fixed', 'price' => 0],
            ['name' => 'Crown & Implant Consultation', 'category' => 'Restorative', 'minutes' => 30, 'type' => 'quote_required'],
        ]);
        KnowledgeItem::create(['organization_id' => $this->orgs['dental']->id, 'type' => 'faq', 'title' => 'Which insurance do you take?', 'content' => 'Most PPO plans: Delta Dental, Cigna, Aetna, MetLife, Guardian. We do not take HMO or Medicaid plans.', 'visibility' => 'public', 'is_pinned' => true]);
        KnowledgeItem::create(['organization_id' => $this->orgs['dental']->id, 'type' => 'policy', 'title' => 'Missed appointments', 'content' => 'Please give 24 hours notice. A $50 fee applies to missed appointments after the first one.', 'visibility' => 'public']);

        $this->travel($this->localAt(92, 11));
        $this->setUpBusiness('law', [
            'legal_name' => 'Lakeside Law Group PLLC', 'business_type' => 'Law firm', 'industry' => 'legal',
            'description' => 'Estate planning, small business and real estate law for Central Texas families and businesses.',
            'service_area' => 'Travis and Williamson counties', 'emergency_available' => false,
        ], [
            ['key' => 'downtown', 'name' => 'Downtown office', 'line1' => '98 San Jacinto Blvd', 'line2' => 'Suite 1200', 'city' => 'Austin', 'zip' => '78701'],
        ], [
            1 => [['09:00', '17:00']], 2 => [['09:00', '17:00']], 3 => [['09:00', '17:00']], 4 => [['09:00', '17:00']], 5 => [['09:00', '16:00']],
        ], [
            ['name' => 'Free Initial Consultation', 'category' => 'Consultations', 'minutes' => 30, 'type' => 'fixed', 'price' => 0],
            ['name' => 'Estate Planning Consultation', 'category' => 'Estate planning', 'minutes' => 60, 'type' => 'fixed', 'price' => 25000],
            ['name' => 'Business Formation Consultation', 'category' => 'Business law', 'minutes' => 60, 'type' => 'fixed', 'price' => 30000],
            ['name' => 'Real Estate Contract Review', 'category' => 'Real estate', 'minutes' => 60, 'type' => 'quote_required'],
        ]);
        KnowledgeItem::create(['organization_id' => $this->orgs['law']->id, 'type' => 'agent_instruction', 'title' => 'No legal advice', 'content' => 'Never give legal advice. Take the caller\'s name, number and a one-line summary, and offer the free 30-minute consultation.', 'visibility' => 'internal', 'is_pinned' => true]);

        $this->travel($this->localAt(104, 10, 45));
        $this->setUpBusiness('yoga', [
            'legal_name' => 'Sunrise Yoga Studio LLC', 'business_type' => 'Yoga studio', 'industry' => 'other',
            'description' => 'Vinyasa, yin and prenatal yoga on South Lamar. Beginners always welcome.',
            'service_area' => 'Austin', 'emergency_available' => false,
        ], [
            ['key' => 'lamar', 'name' => 'South Lamar studio', 'line1' => '3300 S Lamar Blvd', 'line2' => 'Unit 104', 'city' => 'Austin', 'zip' => '78704'],
        ], [
            1 => [['06:30', '20:30']], 2 => [['06:30', '20:30']], 3 => [['06:30', '20:30']], 4 => [['06:30', '20:30']], 5 => [['06:30', '19:00']], 6 => [['08:00', '13:00']], 0 => [['09:00', '12:00']],
        ], [
            ['name' => 'Intro Private Session', 'category' => 'Private', 'minutes' => 60, 'type' => 'fixed', 'price' => 6000],
            ['name' => 'Free Beginner Class', 'category' => 'Classes', 'minutes' => 60, 'type' => 'fixed', 'price' => 0],
            ['name' => 'Corporate Wellness Consultation', 'category' => 'Corporate', 'minutes' => 30, 'type' => 'quote_required'],
        ]);

        $this->travel($this->localAt(7, 13));
        $this->setUpBusiness('hvac', [
            'legal_name' => 'Green Valley Heating & Air LLC', 'business_type' => 'Heating and air conditioning', 'industry' => 'hvac',
            'description' => 'AC repair, tune-ups and new systems for Round Rock, Georgetown and Hutto.',
            'service_area' => 'Round Rock, Georgetown, Hutto, Pflugerville', 'emergency_available' => true,
            'emergency_instructions' => 'No cooling with elderly people, babies or medical needs at home: book the first slot and escalate.',
        ], [
            ['key' => 'roundrock', 'name' => 'Round Rock shop', 'line1' => '2001 Double Creek Dr', 'city' => 'Round Rock', 'zip' => '78664'],
        ], [
            1 => [['07:30', '17:30']], 2 => [['07:30', '17:30']], 3 => [['07:30', '17:30']], 4 => [['07:30', '17:30']], 5 => [['07:30', '17:30']],
        ], [
            ['name' => 'AC Repair', 'category' => 'Cooling', 'minutes' => 90, 'type' => 'starting_from', 'price' => 12900],
            ['name' => 'AC Tune-Up', 'category' => 'Cooling', 'minutes' => 60, 'type' => 'fixed', 'price' => 8900],
            ['name' => 'Furnace Repair', 'category' => 'Heating', 'minutes' => 90, 'type' => 'starting_from', 'price' => 12900],
            ['name' => 'New System Estimate', 'category' => 'Estimates', 'minutes' => 60, 'type' => 'quote_required'],
        ]);

        // Sunrise paused the service ten days ago while the studio is renovated.
        $this->at($this->localAt(10, 9, 20), function () {
            app(ChangeOrganizationStatus::class)->handle($this->orgs['yoga']->fresh(), OrganizationStatus::Paused, $this->admin,
                'Studio closed for renovation until mid-November; the owner asked us to pause answering. Invoice for this period is overdue.');
        });
    }

    // ---------------------------------------------------------------------------------------------
    // Calls, bookings, tasks and escalations
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    private function scenarios(string $key): array
    {
        $common = [
            'spam' => ['w' => 6, 'reason' => 'wrong-number', 'outcome' => 'wrong-number', 'who' => 'none', 'notes' => [
                'Automated recording about an extended car warranty. Ended the call.',
                'Caller selling SEO and "Google listing verification" services. Declined.',
                'Wrong number — caller was looking for a different business.',
                'Silent call, then a fax tone. Ended the call.',
            ]],
            'missed' => ['w' => 4, 'reason' => 'other', 'outcome' => 'call-dropped', 'who' => 'existing', 'notes' => [
                'Caller hung up before giving details.',
                'Bad line — call dropped after the greeting. Tried calling back, no answer.',
            ]],
        ];

        return match ($key) {
            'rivera' => $common + [
                'book_new' => ['w' => 20, 'reason' => 'service-request', 'outcome' => 'scheduled-appointment', 'who' => 'new', 'book' => true],
                'book_existing' => ['w' => 12, 'reason' => 'appointment-scheduling', 'outcome' => 'scheduled-appointment', 'who' => 'existing', 'book' => true],
                'info_new' => ['w' => 9, 'reason' => 'general-inquiry', 'outcome' => 'provided-information', 'who' => 'new', 'notes' => [
                    'Asked whether we service tankless water heaters (yes — Rinnai and Navien). Gave the $79 diagnostic price; will call back to book.',
                    'Asked if we cover {city}. Confirmed we do and explained the booking windows.',
                    'Asked if estimates are free: free for replacements and remodels, $79 diagnostic for repairs (credited if the work goes ahead).',
                    'Asked about financing for a whole-house repipe. Explained Synchrony financing for jobs over $1,500.',
                    'Asked how soon we could replace a 15-year-old water heater. Explained the free estimate; caller will check with spouse.',
                ]],
                'info_existing' => ['w' => 8, 'reason' => 'billing-question', 'outcome' => 'resolved-by-agent', 'who' => 'existing', 'notes' => [
                    'Question about the trip fee on the last invoice. Explained it is waived for service plan members only. Customer satisfied.',
                    'Asked for the warranty on the water heater installed earlier this year: 6-year tank, 1-year labor. Sent nothing, just confirmed.',
                    'Wanted to know the name of the technician who came last time (Luis) to request him again. Noted on the account.',
                    'Asked whether the camera inspection video can be emailed. Confirmed it goes out within 24 hours.',
                ]],
                'message' => ['w' => 6, 'reason' => 'general-inquiry', 'outcome' => 'forwarded-to-client', 'who' => 'any', 'notes' => [
                    'Wants Elena to call about a maintenance contract for three restaurant locations.',
                    'Supplier (Ferguson) calling about a delayed shipment of Bradford White tanks. Asked for the purchasing contact.',
                    'Wanted to pass on a compliment for technician Luis — tidy, on time and explained everything.',
                    'Insurance adjuster asking for the report from the leak repair last month. Needs it emailed.',
                ]],
                'callback' => ['w' => 10, 'reason' => 'service-request', 'outcome' => 'callback-requested', 'who' => 'any', 'notes' => [
                    'Wants a call back with a quote for a whole-house repipe (1970s galvanized pipes). Available after 3 PM.',
                    'Asked for a call back to discuss water softener options and pricing.',
                    'Needs a call back to confirm the replacement part arrived for their water heater repair.',
                    'Property manager wants a call back about scheduling inspections at four rental units.',
                    'Wants to talk to a plumber about moving the washer hookup to the garage before booking.',
                ]],
                'estimate' => ['w' => 4, 'reason' => 'service-request', 'outcome' => 'estimate-requested', 'who' => 'new', 'notes' => [
                    'Wants an estimate for a bathroom remodel (tub to walk-in shower). Will email photos to hello@riveraplumbing.example.',
                    'Wants an estimate for a tankless water heater conversion. Gas line in the attic.',
                    'Wants an estimate for a water softener and whole-house filter. Hard water spots everywhere.',
                ]],
                'emergency' => ['w' => 2, 'reason' => 'emergency-service', 'outcome' => 'escalated-to-client', 'escalation' => 'emergency', 'who' => 'any', 'notes' => [
                    'Burst pipe in the garage, water spraying. Talked the caller through shutting the main valve. Escalated to the on-call technician.',
                    'Sewage backing up into the shower and tub, family with small kids at home. Escalated for immediate dispatch.',
                    'Water heater leaking heavily from the bottom, water spreading to the carpet. Water and power to the unit shut off. Escalated.',
                    'No water at all after city work on the street; caller is elderly and on oxygen. Escalated to the owner.',
                ]],
                'emergency_book' => ['w' => 2, 'reason' => 'emergency-service', 'outcome' => 'scheduled-appointment', 'who' => 'any', 'book' => true, 'emergency' => true, 'notes' => [
                    'Main line backup, toilets overflowing. Booked the emergency call-out within the hour and escalated to the on-call tech.',
                    'Pipe burst under the kitchen sink, main valve closed. Booked the emergency call-out.',
                ]],
                'complaint' => ['w' => 1, 'reason' => 'complaint', 'outcome' => 'escalated-to-client', 'escalation' => 'complaint', 'who' => 'existing', 'notes' => [
                    'Upset that the technician arrived two hours late last Tuesday with no call. Wants a manager to call back today.',
                    'Says the faucet installed last week is dripping again. Expects a free return visit.',
                ]],
                'cancel' => ['w' => 2, 'reason' => 'cancel-appointment', 'outcome' => 'resolved-by-agent', 'who' => 'cancel', 'notes' => [
                    'Called to cancel the upcoming visit — fixed the problem themselves.',
                    'Called to cancel — going out of town; will call back to rebook.',
                ]],
                'followup' => ['w' => 3, 'reason' => 'service-followup', 'outcome' => 'followup-scheduled', 'who' => 'existing', 'notes' => [
                    'Following up on last week\'s drain cleaning — drain is slow again. Business to follow up.',
                    'Asked when the permit inspection for the new water heater will happen. Business to follow up with the date.',
                ]],
            ],
            'dental' => $common + [
                'book_new' => ['w' => 28, 'reason' => 'appointment-scheduling', 'outcome' => 'scheduled-appointment', 'who' => 'new', 'book' => true, 'services' => ['New Patient Exam & X-rays' => 8, 'Whitening Consultation' => 2]],
                'book_existing' => ['w' => 20, 'reason' => 'appointment-scheduling', 'outcome' => 'scheduled-appointment', 'who' => 'existing', 'book' => true, 'services' => ['Cleaning & Check-up' => 8, 'Crown & Implant Consultation' => 2]],
                'emergency_book' => ['w' => 6, 'reason' => 'emergency-service', 'outcome' => 'scheduled-appointment', 'who' => 'any', 'book' => true, 'services' => ['Emergency Dental Visit' => 1], 'notes' => ['Severe toothache since last night, swelling on the left side. Booked the first emergency slot.', 'Cracked a molar on a popcorn kernel. Booked an emergency visit.']],
                'info' => ['w' => 16, 'reason' => 'billing-question', 'outcome' => 'provided-information', 'who' => 'any', 'notes' => ['Asked whether we take Delta Dental PPO (yes).', 'Asked about the cost of whitening. Explained the free consultation.', 'Asked for directions and parking — garage on Aldrich St, first hour free.']],
                'callback' => ['w' => 8, 'reason' => 'billing-question', 'outcome' => 'callback-requested', 'who' => 'existing', 'notes' => ['Wants the billing coordinator to call about an insurance claim that was denied.', 'Wants a call back about payment plans for a crown.']],
                'message' => ['w' => 6, 'reason' => 'general-inquiry', 'outcome' => 'forwarded-to-client', 'who' => 'any', 'notes' => ['Pharmacy calling to confirm a prescription for Dr. Wilson.', 'Patient asking for their X-rays to be sent to an orthodontist.']],
            ],
            'law' => $common + [
                'book_new' => ['w' => 34, 'reason' => 'appointment-scheduling', 'outcome' => 'scheduled-appointment', 'who' => 'new', 'book' => true, 'services' => ['Free Initial Consultation' => 6, 'Estate Planning Consultation' => 3, 'Business Formation Consultation' => 2]],
                'info' => ['w' => 18, 'reason' => 'general-inquiry', 'outcome' => 'provided-information', 'who' => 'new', 'notes' => ['Asked whether the firm handles divorces (no). Suggested the State Bar referral service.', 'Asked what an estate plan costs. Explained the flat-fee packages and the free consultation.']],
                'message' => ['w' => 20, 'reason' => 'general-inquiry', 'outcome' => 'forwarded-to-client', 'who' => 'existing', 'notes' => ['Client asking David to review a revised lease before Friday.', 'Title company asking for signed closing documents.', 'Client wants an update on their LLC filing.']],
                'callback' => ['w' => 14, 'reason' => 'other', 'outcome' => 'callback-requested', 'who' => 'existing', 'notes' => ['Wants David to call back about amending their trust.', 'Wants a call back about a contract dispute with a contractor.']],
            ],
            'hvac' => $common + [
                'book_new' => ['w' => 34, 'reason' => 'service-request', 'outcome' => 'scheduled-appointment', 'who' => 'new', 'book' => true, 'services' => ['AC Repair' => 5, 'AC Tune-Up' => 3, 'New System Estimate' => 2], 'notes' => ['AC blowing warm air since yesterday. Booked a repair visit.', 'Wants a tune-up before the holidays. Booked.', 'System is 18 years old; wants a replacement estimate. Booked.']],
                'emergency' => ['w' => 8, 'reason' => 'emergency-service', 'outcome' => 'escalated-to-client', 'escalation' => 'emergency', 'who' => 'any', 'notes' => ['No cooling, 86°F inside, newborn at home. Escalated to Tom.']],
                'info' => ['w' => 18, 'reason' => 'general-inquiry', 'outcome' => 'provided-information', 'who' => 'new', 'notes' => ['Asked about financing for a new system. Explained the 0% for 18 months offer.', 'Asked if we service Hutto (yes).']],
                'callback' => ['w' => 12, 'reason' => 'service-request', 'outcome' => 'callback-requested', 'who' => 'any', 'notes' => ['Wants Tom to call about a commercial rooftop unit.']],
            ],
            'yoga' => $common + [
                'book_new' => ['w' => 28, 'reason' => 'appointment-scheduling', 'outcome' => 'scheduled-appointment', 'who' => 'new', 'book' => true, 'services' => ['Free Beginner Class' => 4, 'Intro Private Session' => 2]],
                'info' => ['w' => 36, 'reason' => 'general-inquiry', 'outcome' => 'provided-information', 'who' => 'any', 'notes' => ['Asked about the class schedule and mat rental ($3).', 'Asked about prenatal classes (Tuesdays and Thursdays 6 PM).', 'Asked about monthly unlimited pricing ($129).']],
                'message' => ['w' => 14, 'reason' => 'general-inquiry', 'outcome' => 'forwarded-to-client', 'who' => 'any', 'notes' => ['Corporate HR asking about weekly on-site classes for 40 staff.']],
            ],
            default => $common,
        };
    }

    /** @var array<string, int> */
    private const RIVERA_BOOKINGS = [
        'Drain Cleaning' => 24, 'Water Heater Repair' => 15, 'Leak Detection & Repair' => 15, 'Toilet Repair' => 11,
        'Faucet & Fixture Installation' => 10, 'Sewer Line Camera Inspection' => 6, 'Heating Tune-Up' => 9, 'Water Heater Installation' => 5, 'Remodel & Repipe Estimate' => 5,
    ];

    /** @var array<string, list<string>> */
    private const RIVERA_NOTES = [
        'Drain Cleaning' => ['Kitchen sink draining very slowly, tried a plunger and baking soda. Booked a drain cleaning.', 'Main bathroom tub backs up when the washer runs. Booked a drain cleaning.', 'Floor drain in the laundry room gurgling. Booked.'],
        'Water Heater Repair' => ['Water heater making popping noises and only lukewarm water. Booked a repair visit; prefers mornings.', 'Pilot light on the gas water heater keeps going out. Booked.', 'Tankless unit showing error code 11. Booked a repair visit.'],
        'Water Heater Installation' => ['40-gallon tank is 14 years old and rusting at the base. Booked the installation of a 50-gallon Bradford White.', 'Wants to replace the tank with a tankless unit; estimate already approved. Booked the install.'],
        'Leak Detection & Repair' => ['Water stain spreading on the ceiling under the upstairs bathroom. Booked leak detection.', 'Water bill doubled with no visible leak. Booked leak detection for a possible slab leak.', 'Hissing sound in the wall behind the shower. Booked.'],
        'Toilet Repair' => ['Hall toilet runs constantly. Booked a repair. Has a dog — please knock, don\'t ring.', 'Toilet rocks and leaks at the base. Booked a wax ring replacement.'],
        'Faucet & Fixture Installation' => ['Bought a new Moen kitchen faucet, wants it installed. Booked.', 'Garbage disposal seized; wants a new InSinkErator installed. Booked.'],
        'Sewer Line Camera Inspection' => ['Buying a 1960s house, realtor recommended a sewer camera inspection. Booked.', 'Recurring backups twice this year. Booked a camera inspection.'],
        'Heating Tune-Up' => ['Wants the furnace checked before the first cold front. Booked a tune-up.', 'Furnace smells of dust when it starts. Booked a tune-up and safety check.'],
        'Remodel & Repipe Estimate' => ['Planning a master bath remodel; wants a plumber to look before the contractor starts. Booked a free estimate.', 'Galvanized pipes, low pressure everywhere. Booked a free repipe estimate.'],
        'Emergency Call-Out (24/7)' => ['Emergency: water coming through the ceiling. Booked the emergency call-out and escalated.'],
    ];

    private function planCalls(): void
    {
        foreach ($this->specs as $key => $spec) {
            $from = (int) $spec['call_days'];
            $until = (int) ($spec['call_until'] ?? 0);
            $total = (int) $spec['calls'];
            $today = $until === 0 ? ($key === 'rivera' ? 12 : ($key === 'dental' ? 3 : 1)) : 0;
            $past = $total - $today;

            // Busier in recent weeks, quieter at weekends.
            $weights = [];
            for ($d = $from; $d >= max(1, $until); $d--) {
                $dow = $this->day($d)->dayOfWeek;
                $growth = 0.55 + 1.45 * (1 - $d / max(1, $from));
                $weights[$d] = $growth * match ($dow) {
                    0 => 0.3,
                    6 => 0.55,
                    default => 1.0,
                };
            }
            $sum = array_sum($weights);
            $carry = 0.0;
            $counts = [];
            foreach ($weights as $d => $w) {
                $exact = $past * $w / $sum + $carry;
                $counts[$d] = (int) floor($exact);
                $carry = $exact - $counts[$d];
            }
            $counts[array_key_last($counts)] += $past - array_sum($counts);

            foreach ($counts as $d => $n) {
                foreach ($this->dayTimes($d, $n) as $at) {
                    $this->at($at, fn () => $this->answerCall($key));
                }
            }

            if ($today > 0) {
                $start = max($this->day(0)->utc()->getTimestamp() + 60, $this->now->subHours(11)->getTimestamp());
                $end = $this->now->subMinutes(4)->getTimestamp();
                $forced = $key === 'rivera'
                    ? ['book_new', 'emergency', 'info_new', 'book_existing', 'callback', 'spam', 'book_new', 'message', 'estimate', 'info_existing', 'book_existing', 'callback']
                    : ['book_new', 'info', 'book_new'];
                for ($i = 0; $i < $today; $i++) {
                    $ts = (int) ($start + ($end - $start) * ($i + 0.5) / $today);
                    $scenario = $forced[$i % count($forced)];
                    $this->at(CarbonImmutable::createFromTimestamp($ts)->second(mt_rand(0, 59)), fn () => $this->answerCall($key, $scenario));
                }
            }
        }

        // A few calls that came in without a business attached, for the admin's call review.
        foreach ([[2, 21, 'Bryan Holt', '(512) 555-0199', 'Caller asked for "the plumber on Lamar" but hung up before naming the business.'], [5, 9, 'Unknown', null, 'Transferred from the main line; caller could not remember which company they were calling.'], [9, 13, 'Gina Russo', '(737) 555-0198', 'Wanted a dentist near Mueller but no business was selected when the call was saved.']] as [$d, $h, $name, $phone, $notes]) {
            $this->at($this->localAt($d, $h, 12), function () use ($name, $phone, $notes) {
                app(LogCall::class)->handle($this->mike, [
                    'call_date' => now(self::TZ)->toDateString(), 'call_time' => now(self::TZ)->format('H:i'), 'caller_name' => $name === 'Unknown' ? null : $name,
                    'caller_phone' => $phone, 'reason_for_call' => 'general-inquiry', 'call_outcome' => 'other', 'agent_name' => 'Mike', 'status' => 'new', 'notes' => $notes,
                ]);
            });
        }
    }

    /** @return list<CarbonImmutable> */
    private function dayTimes(int $daysAgo, int $count): array
    {
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $minute = $this->chance(9) ? $this->pick([mt_rand(19 * 60 + 30, 22 * 60 + 30), mt_rand(5 * 60 + 30, 6 * 60 + 55)]) : mt_rand(7 * 60 + 5, 19 * 60 + 10);
            $times[] = $this->day($daysAgo)->addMinutes($minute)->addSeconds(mt_rand(0, 59))->utc();
        }
        sort($times);

        return $times;
    }

    private function agentFor(string $key): User
    {
        return match ($key) {
            'rivera' => $this->chance(55) ? $this->john : $this->mike,
            'dental' => $this->chance(80) ? $this->john : $this->mike,
            'law' => $this->john,
            default => $this->mike,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function person(string $key, string $who): ?array
    {
        if ($who === 'none') {
            return null;
        }
        $known = $this->known[$key];
        $new = match ($who) {
            'new' => true,
            'existing' => false,
            default => $this->chance(40),
        };
        if ($new && $this->pool[$key] !== []) {
            return array_shift($this->pool[$key]);
        }
        if ($known === []) {
            return $this->pool[$key] !== [] ? array_shift($this->pool[$key]) : null;
        }

        return $this->pick($known);
    }

    /** One answered call, through the agent workspace's own action. */
    private function answerCall(string $key, ?string $scenarioKey = null): void
    {
        $organization = $this->orgs[$key];
        $scenarios = $this->scenarios($key);
        $scenarioKey ??= $this->weighted(array_map(fn (array $s) => (int) $s['w'], $scenarios));
        $scenario = $scenarios[$scenarioKey];
        $agent = $this->agentFor($key);
        $appointmentToCancel = null;

        if (($scenario['who'] ?? '') === 'cancel') {
            $appointmentToCancel = Appointment::query()->forOrganization($organization)->where('status', AppointmentStatus::Confirmed->value)
                ->where('starts_at', '>', now()->addHours(6))->whereNotNull('customer_id')->inRandomOrder()->first();
            $person = $appointmentToCancel ? $this->personForCustomer($key, (int) $appointmentToCancel->customer_id) : null;
            if (! $person) {
                $this->answerCall($key, 'info_existing');

                return;
            }
        } else {
            $person = $this->person($key, (string) $scenario['who']);
        }
        if ($person === null && $scenario['who'] !== 'none') {
            $this->answerCall($key, 'spam');

            return;
        }

        $local = now($organization->timezoneOrDefault());
        $book = ! empty($scenario['book']);
        $service = $book ? $this->serviceFor($key, $scenario) : null;
        $notes = $this->notesFor($key, $scenario, $service, $person);
        $firstContact = $person !== null && ! isset($this->known[$key][$person['phone']]);
        $visit = $this->specs[$key]['mode'] === 'address';

        $category = match (true) {
            $scenarioKey === 'spam' => 'spam',
            $book => 'booked',
            default => 'other',
        };
        $old = now()->diffInDays($this->now) > 10;
        $status = match (true) {
            $category === 'spam' => 'spam',
            $book => 'service-requested',
            in_array($scenario['outcome'], ['provided-information', 'resolved-by-agent', 'estimate-requested'], true) => $old ? 'completed' : 'information-provided',
            $scenario['outcome'] === 'forwarded-to-client' && $old => 'completed',
            default => 'new',
        };

        $call = app(LogCall::class)->handle($agent, [
            'call_date' => $local->toDateString(),
            'call_time' => $local->format('H:i'),
            'caller_name' => $scenarioKey === 'missed' && $this->chance(50) ? null : ($person['name'] ?? null),
            'caller_phone' => $person['phone'] ?? null,
            'caller_email' => $firstContact ? ($person['email'] ?? null) : null,
            'reason_for_call' => $scenario['reason'],
            'call_outcome' => $scenario['outcome'],
            'agent_name' => Str::before($agent->name.' ', ' '),
            'status' => $status,
            'service_request' => $book,
            'service_location' => $book && $visit && $person ? $this->line($person) : null,
            'notes' => $notes,
            'escalation_type' => $scenario['escalation'] ?? null,
            'address_parts' => $person && $visit && ($firstContact || $book) ? $this->parts($person) : null,
        ], $organization);
        unset($category);

        if ($person && $call->customer_id && $firstContact) {
            // Agents ask how the caller likes to be contacted and record consent (spec §12).
            $consent = mt_rand(1, 100) <= 70;
            Customer::query()->whereKey($call->customer_id)->first()?->forceFill([
                'preferred_contact' => $this->pick(['phone', 'phone', 'sms', 'email']),
                'sms_consent' => $consent, 'sms_consent_at' => $consent ? now() : null,
                'email_consent' => $consent && $person['email'] !== null, 'email_consent_at' => $consent && $person['email'] !== null ? now() : null,
                'consent_source' => $consent ? 'call' : null,
            ])->saveQuietly();
        }
        if ($person && $call->customer_id) {
            $person['customer_id'] = $call->customer_id;
            $this->known[$key][$person['phone']] = $person;
        }

        if ($book && $service) {
            $this->book($key, $service, $call->customer_id, $person, $agent, 'agent', $call->id, $notes, ! empty($scenario['emergency']));
        }
        if ($appointmentToCancel) {
            $reason = Str::after($notes, '— ');
            app(ChangeAppointmentStatus::class)->handle($appointmentToCancel->fresh(), AppointmentStatus::Cancelled, $agent, 'Customer called to cancel: '.$reason);
            $this->sweepAt(now()->addMinutes(61));
        }

        $this->followCallWork($key, $call);
        $this->sweepAt(now()->addMinute());
    }

    /** @return array<string, mixed>|null */
    private function personForCustomer(string $key, int $customerId): ?array
    {
        foreach ($this->known[$key] as $person) {
            if (($person['customer_id'] ?? null) === $customerId) {
                return $person;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $scenario */
    private function serviceFor(string $key, array $scenario): BusinessService
    {
        if (! empty($scenario['emergency']) && $key === 'rivera') {
            return $this->services[$key]['Emergency Call-Out (24/7)'];
        }
        $weights = $scenario['services'] ?? ($key === 'rivera' ? self::RIVERA_BOOKINGS : array_fill_keys(array_keys($this->services[$key]), 1));

        return $this->services[$key][$this->weighted($weights)];
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>|null  $person
     */
    private function notesFor(string $key, array $scenario, ?BusinessService $service, ?array $person): string
    {
        if ($service && $key === 'rivera' && isset(self::RIVERA_NOTES[$service->name]) && empty($scenario['notes'])) {
            $text = $this->pick(self::RIVERA_NOTES[$service->name]);
        } elseif (! empty($scenario['notes'])) {
            $text = $this->pick($scenario['notes']);
        } else {
            $text = 'Booked '.($service->name ?? 'an appointment').'. Confirmed the time and contact details.';
        }

        return strtr($text, ['{city}' => $person['city'] ?? 'their area', '{first}' => $person['first'] ?? 'the caller']);
    }

    /** @param array<string, mixed> $person */
    private function line(array $person): string
    {
        return trim($person['line1'].($person['line2'] ? ', '.$person['line2'] : '').', '.$person['city'].', TX '.$person['zip']);
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array<string, string|null>
     */
    private function parts(array $person): array
    {
        return ['line1' => $person['line1'], 'line2' => $person['line2'], 'city' => $person['city'], 'state' => 'TX', 'postal_code' => $person['zip'], 'country' => 'US'];
    }

    /** Callback tasks get worked, escalations get acknowledged and resolved, as a real business would. */
    private function followCallWork(string $key, CallLog $call): void
    {
        $organization = $this->orgs[$key];
        $owner = $this->specs[$key]['owner'];
        $team = $key === 'rivera' ? [$this->owner, $this->manager, $this->staff] : [$owner];
        $age = $this->now->diffInHours($call->created_at, true);

        foreach ($call->tasks()->get() as $task) {
            $assignee = $this->pick($team);
            $task->forceFill(['assigned_to_user_id' => $assignee->id])->saveQuietly();
            if ($age > 30 || ($age > 16 && $this->chance(50))) {
                if ($key === 'rivera' && $age > 100 && $age < 150 && ! isset($this->sweeps['overdue-left'])) {
                    $this->sweeps['overdue-left'] = true;   // one callback slipped through: shows as overdue

                    continue;
                }
                $this->at($call->created_at->toImmutable()->addMinutes(mt_rand(40, 26 * 60)), function () use ($task, $assignee) {
                    $fresh = $task->fresh();
                    if ($fresh && $fresh->status === TaskStatus::Open) {
                        app(ChangeTaskStatus::class)->handle($fresh, TaskStatus::Completed, $assignee);
                    }
                });
            } elseif ($this->chance(30)) {
                $this->at($call->created_at->toImmutable()->addMinutes(mt_rand(20, 90)), function () use ($task, $assignee) {
                    $fresh = $task->fresh();
                    if ($fresh && $fresh->status === TaskStatus::Open) {
                        app(ChangeTaskStatus::class)->handle($fresh, TaskStatus::InProgress, $assignee);
                    }
                });
            }
        }

        foreach ($call->escalations()->get() as $escalation) {
            $by = $key === 'rivera' ? $this->pick([$this->owner, $this->owner, $this->manager]) : $owner;
            if ($age > 14) {
                $this->at($call->created_at->toImmutable()->addMinutes(mt_rand(6, 35)), fn () => app(AcknowledgeEscalation::class)->handle($escalation->fresh(), $by));
            }
            if ($age > 40) {
                $notes = $this->pick([
                    'On-call tech on site within the hour; repaired and water restored.',
                    'Called the customer back, apologised and booked a free return visit.',
                    'Dispatched Marcus. Main line cleared, camera inspection booked for next week.',
                    'Spoke to the customer, offered a 10% credit on the invoice. Customer happy.',
                    'Resolved by phone; the customer found the shut-off and will book a regular visit.',
                ]);
                $this->at($call->created_at->toImmutable()->addMinutes(mt_rand(90, 400)), fn () => app(ResolveEscalation::class)->handle($escalation->fresh(), $by, $notes));
            }
        }
    }

    /**
     * Books a visit through BookAppointment at the next free slot, and plans what happens to it.
     *
     * @param  array<string, mixed>|null  $person
     */
    private function book(string $key, BusinessService $service, ?int $customerId, ?array $person, ?User $actor, string $source, ?int $callId = null, ?string $notes = null, bool $emergency = false, ?CarbonImmutable $earliest = null, ?string $status = null): ?Appointment
    {
        $organization = $this->orgs[$key];
        $spec = $this->specs[$key];
        $location = $this->locationFor($key, $person);
        $duration = (int) $service->duration_minutes;
        $buffer = (int) $service->buffer_minutes;
        $mode = $spec['mode'];

        $data = [
            'service_id' => $service->id,
            'location_id' => $location->id,
            'customer_id' => $customerId,
            'call_log_id' => $callId,
            'notes' => $notes,
            'location_mode' => $mode,
        ] + ($mode === 'address' && $person ? ['address_parts' => $this->parts($person), 'place_type' => $person['type']] : []);
        if ($status) {
            $data['status'] = $status;
        }

        foreach ($this->slots($key, $location, $duration + $buffer, $emergency, $earliest) as $start) {
            try {
                $appointment = app(BookAppointment::class)->handle($organization, ['starts_at' => $start] + $data, $actor, $source);
            } catch (SlotUnavailable) {
                continue;
            }
            $this->busy[$key.'.'.$location->id][] = [$start->getTimestamp(), $start->addMinutes($duration + $buffer)->getTimestamp()];
            $this->planAppointment($key, $appointment);

            return $appointment;
        }

        return null;
    }

    /** @param array<string, mixed>|null $person */
    private function locationFor(string $key, ?array $person): BusinessLocation
    {
        $locations = $this->locations[$key];
        if ($key === 'rivera' && $person && in_array($person['city'], ['Round Rock', 'Pflugerville', 'Georgetown', 'Cedar Park', 'Leander'], true)) {
            return $locations['roundrock'];
        }

        return reset($locations);
    }

    /** @return \Generator<int, CarbonImmutable> */
    private function slots(string $key, BusinessLocation $location, int $minutes, bool $emergency, ?CarbonImmutable $earliest): \Generator
    {
        $busy = $this->busy[$key.'.'.$location->id] ?? [];
        $free = function (CarbonImmutable $start) use ($busy, $minutes): bool {
            $s = $start->getTimestamp();
            $e = $s + $minutes * 60;
            foreach ($busy as [$bs, $be]) {
                if ($s < $be && $e > $bs) {
                    return false;
                }
            }

            return true;
        };

        if ($emergency) {
            $start = now()->toImmutable()->addMinutes(mt_rand(45, 80));
            $start = $start->setTime($start->hour, intdiv($start->minute, 15) * 15);
            for ($i = 0; $i < 16; $i++, $start = $start->addMinutes(30)) {
                if ($free($start)) {
                    yield $start;
                }
            }

            return;
        }

        $hours = BusinessHour::query()->forOrganization($this->orgs[$key])->get()->groupBy('day_of_week');
        $from = ($earliest ?? now()->toImmutable()->addHours(mt_rand(16, 30)))->setTimezone(self::TZ);
        $skip = $earliest ? 0 : mt_rand(0, 5);
        $day = $from->startOfDay()->addDays($skip);
        for ($n = 0; $n < 30; $n++, $day = $day->addDay()) {
            $spans = $hours->get($day->dayOfWeek);
            if (! $spans) {
                continue;
            }
            $holiday = BusinessHoliday::query()->forOrganization($this->orgs[$key])->whereDate('date', $day->toDateString())->where('is_closed', true)->exists();
            if ($holiday) {
                continue;
            }
            $candidates = [];
            foreach ($spans as $span) {
                $open = $day->setTimeFromTimeString((string) $span->opens_at);
                $close = $day->setTimeFromTimeString((string) $span->closes_at);
                for ($t = $open->addMinutes(60); $t->addMinutes($minutes)->lessThanOrEqualTo($close); $t = $t->addMinutes(60)) {
                    $candidates[] = $t;
                }
            }
            shuffle($candidates);
            foreach ($candidates as $candidate) {
                if ($candidate->greaterThan($from) && $free($candidate->utc())) {
                    yield $candidate->utc();
                }
            }
        }
    }

    /** What happens to a booking: done, no-show or cancelled, depending on when it is. */
    private function planAppointment(string $key, Appointment $appointment): void
    {
        $start = $appointment->starts_at->toImmutable();
        $end = $appointment->ends_at->toImmutable();
        $owner = $this->specs[$key]['owner'];
        $team = $key === 'rivera' ? [$this->owner, $this->manager, $this->staff] : [$owner];
        $roll = mt_rand(1, 100);
        $booked = now()->toImmutable();

        if ($appointment->status !== AppointmentStatus::Confirmed && $appointment->status !== AppointmentStatus::Pending) {
            return;
        }

        if ($roll <= 9) {
            $when = $booked->addSeconds((int) (($start->getTimestamp() - $booked->getTimestamp()) * mt_rand(20, 85) / 100));
            $reason = $this->pick(['Customer found another plumber', 'Customer is travelling that week', 'Problem fixed itself', 'Rescheduling after the holidays', 'Landlord is handling it']);
            $this->at($when, function () use ($appointment, $team, $reason) {
                $fresh = $appointment->fresh();
                if ($fresh && $fresh->status->blocksTime()) {
                    app(ChangeAppointmentStatus::class)->handle($fresh, AppointmentStatus::Cancelled, $this->pick($team), $reason);
                    $this->sweepAt(now()->addMinutes(61));
                }
            });

            return;
        }

        $status = $roll <= 15 ? AppointmentStatus::NoShow : AppointmentStatus::Completed;
        $this->at($end->addMinutes(mt_rand(10, 120)), function () use ($appointment, $status, $team) {
            $fresh = $appointment->fresh();
            if ($fresh && $fresh->status === AppointmentStatus::Pending) {
                app(ChangeAppointmentStatus::class)->handle($fresh, AppointmentStatus::Confirmed, $this->pick($team));
                $fresh = $fresh->fresh();
            }
            if ($fresh && $fresh->status === AppointmentStatus::Confirmed) {
                app(ChangeAppointmentStatus::class)->handle($fresh, $status, $this->pick($team));
                $this->sweepAt(now()->addMinutes(121));
            }
        });
    }

    /** Runs due automations at a moment (each minute only once). */
    private function sweepAt(CarbonImmutable|Carbon $when): void
    {
        $when = CarbonImmutable::instance($when)->startOfMinute();
        $minute = $when->format('Y-m-d H:i');
        if (isset($this->sweeps[$minute])) {
            return;
        }
        $this->sweeps[$minute] = true;
        $this->at($when, fn () => app(AutomationEngine::class)->runDue());
    }

    /** Things the Rivera team did themselves: bookings, quotes and to-dos, a manual escalation, customer places. */
    private function planRiveraExtras(): void
    {
        // Bookings made in the portal by Elena, Marcus and Sofia.
        for ($i = 0; $i < 10; $i++) {
            $daysAgo = mt_rand(2, 112);
            $this->at($this->localAt($daysAgo, mt_rand(8, 16), mt_rand(0, 59)), function () {
                $person = $this->known['rivera'] !== [] ? $this->pick($this->known['rivera']) : null;
                if (! $person) {
                    return;
                }
                $service = $this->services['rivera'][$this->weighted(self::RIVERA_BOOKINGS)];
                $this->book('rivera', $service, $person['customer_id'], $person, $this->pick([$this->owner, $this->manager, $this->manager, $this->staff]), 'portal', null,
                    $this->pick(['Booked by phone with the office.', 'Repeat customer — asked for Luis.', 'Annual check, service plan member.', 'Follow-up visit from the last repair.', null]));
            });
        }

        // Today and tomorrow are busy: booked yesterday and this morning.
        $slots = [[0, 8], [0, 10], [0, 13], [0, 15], [-1, 8], [-1, 9], [-1, 11], [-1, 13], [-1, 15], [-2, 10], [-3, 14]];
        foreach ($slots as $i => [$day, $hour]) {
            $bookedAt = $day === 0 ? $this->localAt(1, 16, 5 * $i) : $this->now->subHours(2 + $i);
            $this->at($bookedAt, function () use ($day, $hour, $i) {
                $person = $this->pick($this->known['rivera']);
                $service = $this->services['rivera'][$this->pick(['Drain Cleaning', 'Water Heater Repair', 'Leak Detection & Repair', 'Toilet Repair', 'Heating Tune-Up', 'Faucet & Fixture Installation'])];
                $start = $this->localAt($day, $hour, 0);
                $actor = $i % 3 === 0 ? $this->john : $this->manager;
                $this->book('rivera', $service, $person['customer_id'], $person, $actor, $i % 3 === 0 ? 'agent' : 'portal', null,
                    $i === 4 ? 'Tentative: customer confirming with landlord.' : null, false, $start->subMinute(), $i === 4 || $i === 9 ? 'tentative' : null);
            });
        }

        // Upcoming work over the next two weeks, booked in the last few days.
        for ($i = 0; $i < 10; $i++) {
            $this->at($this->localAt(mt_rand(0, 4), mt_rand(8, 17), mt_rand(0, 59))->min($this->now->subMinutes(10)), function () {
                $person = $this->pick($this->known['rivera']);
                $service = $this->services['rivera'][$this->weighted(self::RIVERA_BOOKINGS)];
                $this->book('rivera', $service, $person['customer_id'], $person, $this->pick([$this->owner, $this->manager]), 'portal', null, null, false,
                    $this->now->addDays(mt_rand(2, 13)));
            });
        }

        // The team's own to-do list.
        $tasks = [
            [40, 'todo', 'Renew master plumber license (expires Nov 30)', 'Submit TSBPE renewal and CE certificates.', 'high', -45, $this->owner, true],
            [30, 'follow_up', 'Send repipe quote', 'Galvanized to PEX, 2 baths. Use the 2026 price sheet.', 'high', -2, $this->manager, false],
            [12, 'follow_up', 'Order replacement anode rod and thermostat', 'Parts for next week\'s water heater repair.', 'normal', 1, $this->staff, false],
            [9, 'todo', 'Schedule annual backflow test', 'South Congress Cafe — certificate due to the city by month end.', 'normal', 6, $this->manager, false],
            [6, 'follow_up', 'Follow up on unpaid invoice #1187', 'Hill Country Property Management, 45 days overdue.', 'urgent', -1, $this->owner, false],
            [5, 'todo', 'Approve Instagram posts for November', 'SureHelp drafted four posts — check them in Social.', 'normal', 0, $this->owner, false],
            [4, 'follow_up', 'Call back about water softener quote', 'Customer comparing Culligan and our quote.', 'normal', 0, $this->staff, false],
            [3, 'todo', 'Restock van #2', 'PEX fittings, wax rings, 1/2" shut-off valves.', 'low', 3, $this->staff, false],
            [2, 'follow_up', 'Send camera inspection video', 'Email the recording and written report to the buyer\'s realtor.', 'high', 0, $this->manager, false],
            [25, 'todo', 'Update Google Business Profile photos', 'Add the new van wrap and team photo.', 'low', -10, $this->staff, true],
            [18, 'follow_up', 'Thank-you call to Barton Creek Fitness', 'After the locker room repipe.', 'normal', -15, $this->owner, true],
        ];
        foreach ($tasks as [$ago, $type, $title, $description, $priority, $dueIn, $assignee, $done]) {
            $this->at($this->localAt($ago, 9, mt_rand(0, 59)), function () use ($type, $title, $description, $priority, $dueIn, $assignee, $done, $ago) {
                $person = $type === 'follow_up' && $this->known['rivera'] !== [] && ! str_contains($title, 'Barton') ? $this->pick($this->known['rivera']) : null;
                $task = app(CreateTask::class)->handle($this->rivera, [
                    'type' => $type, 'title' => $title, 'description' => $description, 'priority' => $priority,
                    'due_at' => $this->localAt(-$dueIn, 17),
                    'customer_id' => $person['customer_id'] ?? null, 'assigned_to_user_id' => $assignee->id,
                ], $this->owner, 'manual');
                if ($done) {
                    $this->at(now()->toImmutable()->addDays(max(1, intdiv($ago, 2))), fn () => app(ChangeTaskStatus::class)->handle($task->fresh(), TaskStatus::Completed, $assignee));
                }
            });
        }

        // An agent asks the owner to approve a discount.
        $this->at($this->localAt(1, 15, 40), function () {
            $person = $this->pick($this->known['rivera']);
            $escalation = app(RaiseEscalation::class)->handle($this->rivera, [
                'type' => EscalationType::PricingApproval,
                'priority' => 'normal',
                'reason' => $person['name'].': asks for a 15% senior discount on a water heater replacement',
                'details' => 'Customer is 78 and on a fixed income. Our policy allows 10% without approval; they asked for 15%. Estimate was $1,780.',
                'customer_id' => $person['customer_id'],
            ], $this->john, 'manual');
            $this->at(now()->toImmutable()->addMinutes(50), fn () => app(AcknowledgeEscalation::class)->handle($escalation->fresh(), $this->owner));
        });
    }

    // ---------------------------------------------------------------------------------------------
    // Billing
    // ---------------------------------------------------------------------------------------------

    private function planBilling(): void
    {
        // The SEO service was added about ten weeks ago.
        $this->at($this->localAt(70, 11, 20), function () {
            app(ManageAddons::class)->activate($this->rivera->fresh(), $this->addons['seo_service'], $this->owner);
            $this->rivera->forceFill(['seo_settings' => [
                'autopilot' => true,
                'areas' => ['Austin', 'Round Rock', 'Pflugerville', 'Cedar Park', 'Georgetown'],
                'keywords' => ['plumber austin', 'emergency plumber', 'water heater repair', 'drain cleaning', 'tankless water heater installation', 'slab leak repair'],
            ]])->save();
            $this->payNewInvoices();
        });
        $this->at($this->localAt(110, 9), fn () => app(ManageAddons::class)->activate($this->orgs['dental']->fresh(), $this->addons['bilingual'], $this->specs['dental']['owner']));

        // The nightly billing run, then customers paying a few days later.
        for ($d = 131; $d >= 0; $d--) {
            $this->at($this->localAt($d, 1, 30), function () {
                app(BillingRun::class)->run(CarbonImmutable::today(self::TZ)->utc()->startOfDay());
                $this->payNewInvoices();
            });
        }
    }

    private function payNewInvoices(): void
    {
        $open = Invoice::withoutGlobalScopes()->where('status', InvoiceStatus::Open->value)->whereNotIn('id', array_keys($this->invoiceHandled))->get();
        foreach ($open as $invoice) {
            $this->invoiceHandled[$invoice->id] = true;
            $issued = $invoice->issued_at->toImmutable();
            $key = array_search($invoice->organization_id, array_map(fn (Organization $o) => $o->id, $this->orgs), true);

            if ($key === 'yoga' && $issued->greaterThan($this->now->subDays(40))) {
                continue;   // Sunrise stopped paying: the invoice goes overdue.
            }
            if ($key === 'rivera' && $issued->greaterThan($this->now->subDays(12)) && $invoice->subscription_id) {
                // The current invoice: part paid, the rest due later.
                $this->at($issued->addHours(30)->min($this->now->subHours(3)), fn () => app(RecordPayment::class)->handle($invoice->fresh(), intdiv($invoice->total_cents, 2), PaymentMethod::BankTransfer,
                    'ACH-'.strtoupper(Str::random(8)), $this->admin, null, 'Partial payment by ACH; balance promised by the due date.'));

                continue;
            }

            $method = $key === 'law' ? PaymentMethod::BankTransfer : $this->pick([PaymentMethod::PayoneerCard, PaymentMethod::PayoneerCard, PaymentMethod::PayoneerBank]);
            $this->at($issued->addHours(mt_rand(20, 110)), function () use ($invoice, $method) {
                $fresh = $invoice->fresh();
                if ($fresh && $fresh->status === InvoiceStatus::Open) {
                    app(RecordPayment::class)->handle($fresh, $fresh->balanceCents(), $method, 'PYN-'.strtoupper(Str::random(10)), $this->admin);
                }
            });
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Inbox: website chat, Messenger and Instagram
    // ---------------------------------------------------------------------------------------------

    /** @var array<string, SocialAccount> */
    private array $accounts = [];

    private function planInbox(): void
    {
        $this->at($this->localAt(100, 10), function () {
            $org = $this->rivera;
            $common = ['organization_id' => $org->id, 'is_enabled' => true, 'status' => 'active', 'connected_by_user_id' => $this->owner->id, 'token_expires_at' => now()->addDays(160)];
            $this->accounts['facebook'] = SocialAccount::create($common + ['network' => SocialNetwork::Facebook, 'external_id' => '104729384756', 'name' => 'Rivera Plumbing & Heating', 'handle' => 'riveraplumbingatx',
                'access_token' => 'demo-page-token-'.Str::random(24), 'messaging_enabled' => true, 'meta' => ['category' => 'Plumbing Service']]);
            $this->accounts['instagram'] = SocialAccount::create($common + ['network' => SocialNetwork::Instagram, 'external_id' => '17841405822304914', 'name' => 'Rivera Plumbing', 'handle' => 'riveraplumbing.atx',
                'access_token' => 'demo-ig-token-'.Str::random(24), 'messaging_enabled' => true, 'meta' => ['page_id' => '104729384756']]);
            $this->accounts['google'] = SocialAccount::create($common + ['network' => SocialNetwork::GoogleBusiness, 'external_id' => 'accounts/1093847561/locations/7720394851', 'name' => 'Rivera Plumbing & Heating — Austin',
                'access_token' => 'demo-google-token-'.Str::random(24), 'refresh_token' => 'demo-google-refresh-'.Str::random(24), 'meta' => ['account' => 'accounts/1093847561']]);
            $this->accounts['linkedin'] = SocialAccount::create($common + ['network' => SocialNetwork::LinkedIn, 'external_id' => 'urn:li:organization:90817263', 'name' => 'Rivera Plumbing & Heating LLC',
                'access_token' => 'demo-linkedin-token-'.Str::random(24), 'refresh_token' => 'demo-linkedin-refresh-'.Str::random(24)]);
        });

        $convos = [
            // [days ago, hour, channel, name, contact, steps, final state]
            [9, 10, 'web_chat', 'Kayla Brooks', ['email' => 'kayla.brooks@example.com'], [
                ['in', 'Hi! How much does it cost to replace a 50 gallon gas water heater?'],
                ['ai', 'Hi Kayla! Replacements are always priced on site, because it depends on venting, permits and access — but the estimate is free. Would you like me to book a technician to take a look?'],
                ['in', 'Yes please, any time Thursday morning'],
                ['ai', "Done! You're booked for a free water heater estimate on Thursday at 9:00 AM. You'll get a confirmation email shortly. Anything else I can help with?"],
                ['in', 'Perfect, thanks!'],
            ], 'closed'],
            [6, 20, 'web_chat', null, [], [
                ['in', 'do you guys serve pflugerville'],
                ['ai', 'Yes, we do! Pflugerville is covered from our Round Rock branch. Regular visits are Monday–Friday 7–6 and Saturday 8–2, and emergencies 24/7. Can I help you book something?'],
                ['in', 'not yet, just checking. thanks'],
            ], 'open'],
            [0, -95, 'web_chat', 'Brian Lewis', ['phone' => '(512) 555-0287'], [
                ['in', 'There is water leaking from under my kitchen sink cabinet, not a lot but steady'],
                ['ai', "Thanks Brian. Please turn the two small valves under the sink clockwise to stop the water, and put a towel down. I've passed this to the Rivera team so a person can get you booked in — they'll reply here shortly."],
                ['in', 'ok valves are off. how soon can someone come?'],
            ], 'needs_you'],
            [14, 11, 'facebook', 'Derek Lawson', [], [
                ['in', 'Hey, do you do bathroom remodels or just repairs? Looking to convert a tub to a walk-in shower.'],
                ['team', 'Hi Derek! Yes, we do the plumbing for remodels — moving drains, new shower valves, all of it. The estimate is free. Can you send a couple of photos of the bathroom?', 'manager'],
                ['in', '[photo] here you go'],
                ['team', 'Thanks! That layout works well for a walk-in. I can come Tuesday at 3 PM for the estimate, does that suit?', 'manager'],
                ['in', 'Tuesday 3 works 👍'],
            ], 'closed'],
            [3, 16, 'facebook', 'Angela Moore', [], [
                ['in', 'Your tech was supposed to come between 8 and 10 today and nobody showed up or called. I took the morning off work for this.'],
                ['team', "Angela, I'm really sorry — that's not how we do things. Our first job ran long and dispatch didn't call you, which they should have. I've booked you first thing tomorrow at 7 AM and we'll waive the diagnostic fee. — Elena", 'owner'],
                ['in', 'Ok. Appreciate the reply. 7am is fine.'],
                ['note', 'Waived the $79 diagnostic. Check with Luis why the 8–10 slot was missed.', 'owner'],
            ], 'open'],
            [0, -25, 'facebook', 'Tom Nguyen', [], [
                ['in', 'Hi, what does a sewer camera inspection cost? Buying a house in Allandale built 1958'],
                ['in', 'Also do you give a report I can send to my realtor?'],
                ['draft', 'Hi Tom! A sewer camera inspection is $299 and you get the recorded video plus a written report you can share with your realtor. For a 1958 home it is a really good idea. Would you like to book a time?'],
            ], 'needs_you'],
            [5, 13, 'instagram', 'Jess Alvarez', ['handle' => '@atx_homes_jess'], [
                ['in', 'Love the tankless install you posted! Is that something you can do in an older house?'],
                ['ai', 'Thank you! 😊 Yes — most older homes can go tankless, though some need a bigger gas line or new venting. The technician checks that during a free estimate. Want me to book one?'],
                ['in', 'Maybe next month, will reach out!'],
            ], 'open'],
            [21, 12, 'instagram', 'Chris Morales', ['handle' => '@chrismorales.plumb'], [
                ['in', 'Are you hiring? Licensed journeyman, 6 yrs experience'],
                ['team', 'Hi Chris! We are — please send your resume to jobs@riveraplumbing.example and mention Instagram. Marcus will be in touch.', 'staff'],
                ['in', 'Sent! Thanks'],
            ], 'closed'],
            [2, 9, 'web_chat', 'Patricia Hall', ['email' => 'patricia.hall@example.org'], [
                ['in', 'Can I book a drain cleaning for tomorrow? The bathtub drains very slowly'],
                ['ai', "Of course, Patricia! I've booked a drain cleaning for tomorrow at 1:00 PM. The technician will text before arriving. Is there a gate code or pet we should know about?"],
                ['in', 'No gate, one friendly cat'],
                ['ai', 'Noted — thanks! See you tomorrow.'],
            ], 'closed', 'book'],
            [0, -400, 'web_chat', 'Robert King', ['phone' => '(737) 555-0293'], [
                ['in', 'URGENT water pouring from ceiling in upstairs bathroom!!!'],
                ['ai', "Robert, please shut off the main water valve right away — it's usually in the box near the curb or in the garage. Turn off electricity to that area if water is near lights or outlets. I'm alerting the on-call technician now."],
                ['in', 'Main is off. Please send someone'],
            ], 'escalate'],
            [11, 15, 'facebook', 'Linda Carter', [], [
                ['in', 'Just wanted to say Luis was fantastic yesterday. On time, explained everything, cleaned up after. 5 stars!'],
                ['team', 'Thank you so much, Linda! I\'ll make sure Luis sees this. If you have a minute, a Google review would mean a lot to our small team 🙏', 'owner'],
                ['note', 'Shared with Luis at the Monday meeting.', 'manager'],
            ], 'closed'],
            [0, -50, 'web_chat', 'Nancy Ward', ['email' => 'nancy.ward@example.net'], [
                ['in', 'Hello, could you email me a copy of my invoice from the water heater install in August? I need it for my home warranty claim.'],
            ], 'needs_you'],
        ];

        foreach ($convos as $i => $convo) {
            [$ago, $hour] = $convo;
            $start = $hour < 0 ? $this->now->addMinutes($hour) : $this->localAt($ago, $hour, mt_rand(0, 50));
            $this->at($start, fn () => $this->conversation($i, $convo));
        }
    }

    /** @param array<int, mixed> $convo */
    private function conversation(int $index, array $convo): void
    {
        [, , $channel, $name, $contact, $steps, $state] = $convo;
        $extra = $convo[7] ?? null;
        $org = $this->rivera;
        $channelEnum = InboxChannel::from($channel);
        $account = $channel === 'web_chat' ? null : $this->accounts[$channel];
        $thread = $channel === 'web_chat' ? hash('sha256', 'visitor-'.$index) : 'psid-'.(7300000000 + $index * 7919);
        $conversation = null;
        $users = ['owner' => $this->owner, 'manager' => $this->manager, 'staff' => $this->staff];

        foreach ($steps as $s => $step) {
            if ($s > 0) {
                $this->travel($this->clock->addMinutes(mt_rand(1, 6))->min($this->now->subMinute()));
            }
            [$kind, $body] = $step;
            if ($kind === 'in') {
                app(ReceiveMessage::class)->handle($org, $channelEnum, [
                    'channel_key' => $account ? $account->external_id : 'web',
                    'thread' => $thread,
                    'body' => $body,
                    'external_id' => $account ? 'mid.'.Str::random(20) : null,
                    'name' => $name,
                    'handle' => $contact['handle'] ?? null,
                    'email' => $contact['email'] ?? null,
                    'phone' => $contact['phone'] ?? null,
                    'social_account_id' => $account?->id,
                ]);
                $conversation = Conversation::query()->forOrganization($org)->where('external_thread_id', $thread)->firstOrFail();

                continue;
            }
            if (! $conversation) {
                continue;
            }
            if ($kind === 'ai' || $kind === 'draft') {
                $run = AiRun::create(['organization_id' => $org->id, 'conversation_id' => $conversation->id, 'mode' => $kind === 'ai' ? 'auto' : 'suggest', 'model' => 'claude-opus-5-5',
                    'status' => $kind === 'ai' ? 'replied' : 'drafted', 'steps' => mt_rand(1, 3), 'input_tokens' => mt_rand(5200, 9800), 'output_tokens' => mt_rand(90, 260), 'cache_read_tokens' => mt_rand(3000, 7000),
                    'tool_calls' => $extra === 'book' && $s === 3 ? [['name' => 'book_appointment', 'input' => ['service' => 'Drain Cleaning'], 'ok' => true, 'summary' => 'Booked Drain Cleaning']] : null]);
                Message::create(['organization_id' => $org->id, 'conversation_id' => $conversation->id, 'direction' => Message::OUT, 'author_type' => 'ai', 'body' => $body,
                    'status' => $kind === 'ai' ? 'sent' : 'draft', 'ai_run_id' => $run->id, 'sent_at' => $kind === 'ai' ? now() : null]);
                if ($kind === 'ai') {
                    $conversation->forceFill(['last_message_at' => now(), 'needs_human' => false, 'unread_count' => 0])->save();
                }

                continue;
            }
            $user = $users[$step[2] ?? 'owner'];
            if ($kind === 'note') {
                app(SendReply::class)->handle($conversation->fresh(), $body, $user, note: true);

                continue;
            }
            if ($channelEnum === InboxChannel::WebChat) {
                app(SendReply::class)->handle($conversation->fresh(), $body, $user);
            } else {
                // Meta replies are recorded as the Graph API would have confirmed them.
                Message::create(['organization_id' => $org->id, 'conversation_id' => $conversation->id, 'direction' => Message::OUT, 'author_type' => 'user', 'author_user_id' => $user->id,
                    'body' => $body, 'status' => 'sent', 'external_id' => 'm_'.Str::random(24), 'sent_at' => now()]);
                $conversation->forceFill(['last_message_at' => now(), 'ai_paused' => true, 'needs_human' => false, 'unread_count' => 0, 'assigned_to_user_id' => $conversation->assigned_to_user_id ?? $user->id])->save();
            }
        }

        if (! $conversation) {
            return;
        }
        $conversation = $conversation->fresh();
        if ($extra === 'book' && $conversation->customer_id) {
            $person = ['first' => 'Patricia', 'last' => 'Hall', 'name' => 'Patricia Hall', 'phone' => '(512) 555-0288', 'line1' => '4410 Shoal Creek Blvd', 'line2' => null, 'city' => 'Austin', 'zip' => '78756', 'type' => 'residential'];
            $conversation->customer?->forceFill(['phone' => $person['phone'], 'address_line1' => $person['line1'], 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78756'])->save();
            $this->book('rivera', $this->services['rivera']['Drain Cleaning'], $conversation->customer_id, $person, null, 'ai', null, 'Booked by Rosie (AI assistant) in website chat. One friendly cat.', false,
                $this->localAt(1, 12, 59)->max(now()->toImmutable()->addHours(20)));
        }
        if ($state === 'escalate') {
            app(RaiseEscalation::class)->handle($org, [
                'type' => EscalationType::Emergency, 'priority' => 'urgent',
                'reason' => ($name ?? 'Website visitor').': water pouring from the upstairs bathroom ceiling',
                'details' => 'Reported in website chat. Main valve is off. Customer asked for someone to come now. Phone '.($contact['phone'] ?? 'not given').'.',
                'customer_id' => $conversation->customer_id,
            ], null, 'ai');
            $conversation->forceFill(['needs_human' => true, 'ai_paused' => true])->save();
        }
        match ($state) {
            'closed' => $conversation->forceFill(['status' => 'closed', 'closed_at' => now()->addMinutes(20), 'needs_human' => false, 'unread_count' => 0])->save(),
            'needs_you' => $conversation->forceFill(['needs_human' => true])->save(),
            'open' => $conversation->forceFill(['needs_human' => false, 'unread_count' => 0])->save(),
            default => null,
        };
    }

    // ---------------------------------------------------------------------------------------------
    // Support requests, call quality, shifts
    // ---------------------------------------------------------------------------------------------

    private function planSupport(): void
    {
        $desk = fn () => app(SupportDesk::class);

        $this->at($this->localAt(48, 10, 5), function () use ($desk) {
            $ticket = $desk()->open($this->rivera, $this->owner, ['subject' => 'Please update the after-hours emergency script', 'category' => 'script_change', 'priority' => 'normal',
                'body' => "Hi team,\n\nFrom next Monday our on-call technician is Marcus (512) 555-0110 instead of Luis. Could you update the emergency instructions your agents use?\n\nThanks,\nElena"]);
            $this->travel($this->clock->addHours(2));
            $desk()->assign($ticket, $this->admin, $this->admin);
            $desk()->reply($ticket, $this->admin, "Hi Elena,\n\nDone — the emergency instructions now name Marcus as on-call from Monday, and I've briefed John and Mike. Let us know if anything else changes.\n\nAlex, SureHelp support", true, null, SupportTicketStatus::Resolved);
        });

        $this->at($this->localAt(4, 14, 20), function () use ($desk) {
            $ticket = $desk()->open($this->rivera, $this->manager, ['subject' => 'Some appointments show twice in Google Calendar', 'category' => 'technical', 'priority' => 'high',
                'body' => "Since yesterday a few bookings appear twice in our shared Google Calendar (for example Thursday 10 AM). Can you check?\n\nMarcus"]);
            $this->travel($this->clock->addMinutes(45));
            $desk()->assign($ticket, $this->admin, $this->admin);
            $desk()->reply($ticket, $this->admin, 'Thanks Marcus. Could you tell me whether the duplicate is in the "Rivera Jobs" calendar or in your personal one? A screenshot helps too.', true);
            $this->travel($this->clock->addHours(3));
            $desk()->reply($ticket, $this->manager, 'It is in Rivera Jobs. One copy has the customer name, the other just says "Busy". Screenshot attached to the email I sent.', false);
        });

        $this->at($this->now->subHours(5), function () use ($desk) {
            $desk()->open($this->rivera, $this->owner, ['subject' => 'Question about the October invoice', 'category' => 'billing', 'priority' => 'low',
                'body' => 'Hi, the October invoice includes the SEO service — is that billed every month or was it a one-off setup? Just want to budget correctly. Thanks!']);
        });

        $this->at($this->localAt(30, 9, 30), function () use ($desk) {
            $ticket = $desk()->open($this->orgs['dental'], $this->specs['dental']['owner'], ['subject' => 'Can agents book hygiene appointments on Fridays?', 'category' => 'question', 'priority' => 'normal',
                'body' => 'We now open until 2 PM on Fridays. Can your team start booking cleanings then?']);
            $this->travel($this->clock->addHours(1));
            $desk()->reply($ticket, $this->admin, 'Yes! Your Friday hours (8 AM–2 PM) are already in your business hours, so agents can book cleanings then. Nothing else to change.', true, null, SupportTicketStatus::Closed);
        });

        // Shift requests.
        $this->at($this->localAt(1, 18), function () {
            ShiftRequest::create(['agent_id' => $this->mike->id, 'type' => ShiftRequest::LEAVE, 'leave_from' => $this->day(-8)->toDateString(), 'leave_until' => $this->day(-9)->toDateString(),
                'reason' => 'Family wedding in San Antonio.']);
        });
        $this->at($this->localAt(12, 10), function () {
            ShiftRequest::create(['agent_id' => $this->john->id, 'type' => ShiftRequest::LEAVE, 'leave_from' => $this->day(9)->toDateString(), 'leave_until' => $this->day(9)->toDateString(),
                'reason' => 'Dentist appointment in the morning.', 'status' => ShiftRequest::APPROVED, 'decided_by' => $this->admin->id, 'decided_at' => now()->addHours(3), 'decision_note' => 'Approved — Mike covers the morning.']);
        });
    }

    /** @return list<array{0: int, 1: string, 2: array<string, string>, 3: string, 4: ?string}> */
    private function qualityReviews(): array
    {
        return [
            [38, 'john', ['greeting' => 'met', 'accuracy' => 'met', 'booking_attempt' => 'met', 'tone' => 'met', 'compliance' => 'met'], 'Warm greeting, confirmed the address twice and booked the first slot.', null],
            [33, 'mike', ['greeting' => 'met', 'accuracy' => 'partly', 'booking_attempt' => 'met', 'tone' => 'met', 'compliance' => 'met'], 'Good energy and booked the visit.', 'Notes missed the gate code the caller mentioned — add every access detail.'],
            [27, 'john', ['greeting' => 'met', 'accuracy' => 'met', 'booking_attempt' => 'na', 'tone' => 'met', 'compliance' => 'met'], 'Calm with an upset caller, escalated correctly.', null],
            [22, 'mike', ['greeting' => 'partly', 'accuracy' => 'met', 'booking_attempt' => 'missed', 'tone' => 'met', 'compliance' => 'met'], 'Accurate notes.', 'Use the full Rivera greeting, and offer the free estimate when someone asks about prices.'],
            [17, 'john', ['greeting' => 'met', 'accuracy' => 'met', 'booking_attempt' => 'met', 'tone' => 'partly', 'compliance' => 'met'], 'Booked quickly and correctly.', 'Slow down slightly at the end of the call; recap the appointment time.'],
            [12, 'mike', ['greeting' => 'met', 'accuracy' => 'met', 'booking_attempt' => 'met', 'tone' => 'met', 'compliance' => 'met'], 'Great improvement on the greeting — textbook call.', null],
            [8, 'john', ['greeting' => 'met', 'accuracy' => 'partly', 'booking_attempt' => 'met', 'tone' => 'met', 'compliance' => 'missed'], 'Friendly and efficient.', 'The caller mentioned a gas smell: they must be told to leave the house and call the gas company first. Please re-read the Rivera emergency instructions.'],
            [5, 'mike', ['greeting' => 'met', 'accuracy' => 'met', 'booking_attempt' => 'met', 'tone' => 'met', 'compliance' => 'met'], 'Handled two questions and a booking smoothly.', null],
            [3, 'john', ['greeting' => 'met', 'accuracy' => 'met', 'booking_attempt' => 'met', 'tone' => 'met', 'compliance' => 'met'], 'Excellent emergency handling: shut-off instructions, booked and escalated.', null],
        ];
    }

    private function planQuality(): void
    {
        $reviews = $this->qualityReviews();
        foreach ($reviews as [$ago, $who, $marks, $strengths, $improvements]) {
            $this->at($this->localAt($ago, 18, 30), function () use ($who, $marks, $strengths, $improvements, $ago) {
                $agent = $who === 'john' ? $this->john : $this->mike;
                $call = CallLog::query()->where('user_id', $agent->id)->whereNotNull('organization_id')->where('status', '!=', 'spam')
                    ->where('created_at', '<', now())->where('created_at', '>', now()->subDays(3))->whereDoesntHave('qaReview')->inRandomOrder()->first();
                if (! $call) {
                    return;
                }
                $quality = app(QualityReviews::class);
                $review = $quality->start($call, $this->admin);
                $this->travel($this->clock->addMinutes(12));
                $review = $quality->complete($review, $this->admin, $marks, $strengths, $improvements);
                if ($ago > 4) {
                    $review->forceFill(['acknowledged_at' => now()->addHours(mt_rand(14, 30))])->save();
                }
            });
        }
        if (! $this->sandbox) {   // the sample spans every business, real ones too
            $this->at($this->localAt(0, 6, 0)->min($this->now->subMinutes(30)), fn () => app(QualityReviews::class)->drawSample(now()->subDay()));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // After the timeline: customers, social, website, SEO, calendar, schedules
    // ---------------------------------------------------------------------------------------------

    private function finishCustomers(): void
    {
        $tags = Tag::query()->forOrganization($this->rivera)->pluck('id', 'name');
        $notes = [
            'Prefers text messages before the technician arrives.',
            'Two large dogs in the backyard — call on arrival.',
            'Gate code 4471. Water heater is in the attic.',
            'Asked for Luis on future visits.',
            'Older home (1962), cast-iron drain lines.',
            'Tenant-occupied; landlord approves work over $300.',
            'Pays by check on site.',
            'Interested in the annual service plan.',
        ];
        $i = 0;
        foreach (Customer::query()->forOrganization($this->rivera)->orderBy('id')->get() as $customer) {
            $i++;
            $changes = ['preferred_contact' => $customer->preferred_contact ?? $this->pick(['phone', 'sms', 'email'])];
            if ($customer->notes === null && $i % 3 === 1) {
                $changes['notes'] = $notes[$i % count($notes)];
            }
            if ($customer->status->value === 'lead' && $customer->created_at->lessThan($this->now->subDays(30)) && $i % 2 === 0) {
                $changes['status'] = 'prospect';
            }
            if ($i % 23 === 0) {
                $changes['status'] = 'inactive';
            }
            $customer->forceFill($changes)->saveQuietly();

            $attach = [];
            if ($customer->company) {
                $attach[] = $tags['Commercial'];
            }
            if (Appointment::query()->where('customer_id', $customer->id)->where('status', 'completed')->count() >= 2) {
                $attach[] = $tags['Repeat customer'];
            }
            foreach (['VIP' => 11, 'Service plan member' => 5, 'Warranty' => 7, 'Senior discount' => 13] as $tag => $every) {
                if ($i % $every === 0) {
                    $attach[] = $tags[$tag];
                }
            }
            if ($customer->company && str_contains((string) $customer->company, 'Property')) {
                $attach[] = $tags['Rental property'];
                foreach ([['Elm St duplex', '1204 Elm St', 'Austin', '78703'], ['Riverside fourplex', '2210 Riverside Dr', 'Austin', '78741'], ['Pecan Park unit 3', '815 Pecan St', 'Pflugerville', '78660']] as [$name, $line, $city, $zip]) {
                    CustomerLocation::create(['organization_id' => $this->rivera->id, 'customer_id' => $customer->id, 'name' => $name, 'type' => 'residential', 'address_line1' => $line, 'city' => $city, 'state' => 'TX', 'postal_code' => $zip, 'last_used_at' => $this->now->subDays(mt_rand(3, 60))]);
                }
            }
            if ($attach !== []) {
                $customer->tags()->syncWithoutDetaching(array_unique($attach));
            }
        }
    }

    /** @return list<array{0: string, 1: ?int, 2: string, 3: list<SocialAccount>, 4: ?string, 5: ?string}> */
    private function postPlan(SocialAccount $fb, SocialAccount $ig, SocialAccount $gbp, SocialAccount $li): array
    {
        return [
            ['published', -62, "We're 17 years old this month! 🎉 Thank you, Austin, for trusting a small family business with your homes. Here's to many more years of hot showers and happy drains.", [$fb, $ig, $li], 'team', null],
            ['published', -41, "Thinking about going tankless? Endless hot water, lower energy bills and 6 extra square feet in your garage. Book a free estimate and we'll tell you honestly whether it makes sense for your home.", [$fb, $ig, $gbp], 'tankless', 'https://riveraplumbing.example/tankless-water-heaters'],
            ['published', -27, 'Slow drain? Skip the chemical cleaners — they can damage older pipes. Our professional drain cleaning is $149, flat rate, any drain.', [$fb, $gbp], null, 'https://riveraplumbing.example/drain-cleaning'],
            ['published', -14, 'Meet Luis, our lead technician for 9 years. Ask anyone in Round Rock — he leaves every kitchen cleaner than he found it.', [$fb, $ig], 'team', null],
            ['published', -6, 'Our new van wrap is out on the road! Wave if you see us around Austin and Round Rock 👋', [$fb, $ig, $li], 'van', null],
            ['partly_published', -9, 'Fall is the best time for a heating tune-up: $99 safety check including a carbon-monoxide test. Book before the first cold front.', [$fb, $gbp, $li], null, 'https://riveraplumbing.example/heating'],
            ['failed', -3, 'Freeze warning this weekend? Disconnect garden hoses, cover outdoor faucets and keep cabinet doors open under sinks on exterior walls.', [$ig], 'winter', null],
            ['scheduled', 3, 'Hosting family for the holidays? Book a drain cleaning before the guests arrive — kitchen sinks work overtime in November!', [$fb, $gbp], null, 'https://riveraplumbing.example/book'],
            ['scheduled', 8, 'Freeze prep checklist ❄️ 1) Insulate exposed pipes 2) Know where your main shut-off is 3) Drip faucets on hard-freeze nights. Questions? We are here 24/7.', [$fb, $ig, $li], 'winter', null],
            ['scheduled', 15, 'Thank you to our 300+ five-star reviewers! Every review helps a small business. 🙏', [$fb, $gbp], null, null],
            ['in_review', 5, 'Water heater older than 10 years? Signs it is time to replace: rusty water, rumbling noises, puddles at the base. Free replacement estimates all month.', [$fb, $ig, $gbp], 'tankless', 'https://riveraplumbing.example/water-heaters'],
            ['in_review', 10, 'Holiday hours: closed Thanksgiving Day, emergency line open 24/7 as always.', [$fb, $gbp, $li], null, null],
            ['draft', null, 'Idea: before/after of the Allandale bathroom repipe — need photos from Marcus.', [], null, null],
        ];
    }

    /** Google reviews over the last year (D83): mostly happy, a few to answer. */
    private function reviews(): void
    {
        $account = $this->accounts['google'] ?? null;
        if (! $account) {
            return;
        }
        $names = ['Maria G.', 'Tom Becker', 'Priya Nair', 'Jason Lee', 'Carla Ruiz', 'Mike O\'Neil', 'Sandra Kim', 'Luis Ortega', 'Emily Chen', 'David Park', 'Rachel Adams', 'Omar Haddad'];
        $good = ['Fixed our water heater the same day. Friendly and on time.', 'Great communication and a fair price. Will use again.', 'The tech explained everything and cleaned up after.',
            'Booked online at night and they came first thing. Excellent!', 'Answered on the first ring and sent someone within two hours.', 'Honest advice, didn\'t try to upsell us.'];
        $mixed = ['Good work but they arrived an hour late.', 'Job done fine, a bit pricey.'];
        $bad = ['Waited all morning and nobody called to say they were running late.'];
        $replies = ['Thank you so much! We\'re glad we could help. Rivera Plumbing', 'Thanks for choosing us, and for the kind words! Rivera Plumbing'];
        mt_srand(83);
        $total = 0;
        $sum = 0;
        for ($i = 0; $i < 42; $i++) {
            $days = (int) round(365 * ($i / 42) ** 1.3) + mt_rand(0, 3);
            $roll = mt_rand(1, 100);
            [$rating, $comment] = match (true) {
                $roll <= 4 => [1, $bad[0]],
                $roll <= 12 => [3, $mixed[mt_rand(0, 1)]],
                $roll <= 30 => [4, $good[mt_rand(0, 5)]],
                default => [5, $good[mt_rand(0, 5)]],
            };
            $at = $this->now->subDays($days)->setTime(mt_rand(8, 20), mt_rand(0, 59));
            $waiting = $days <= 6;
            Review::create(['organization_id' => $this->rivera->id, 'social_account_id' => $account->id, 'source' => 'google',
                'external_id' => $account->external_id.'/reviews/demo'.$i, 'author_name' => $names[$i % count($names)], 'rating' => $rating, 'comment' => $comment,
                'reviewed_at' => $at, 'reply' => $waiting ? null : $replies[$i % 2], 'replied_at' => $waiting ? null : $at->addHours(mt_rand(2, 30)),
                'replied_by_user_id' => $waiting ? null : $this->owner->id, 'status' => $waiting ? Review::NEW : Review::REPLIED]);
            $total++;
            $sum += $rating;
        }
        $account->forceFill(['meta' => array_merge($account->meta ?? [], ['reviews_synced_at' => $this->now->toIso8601String(),
            'rating' => round($sum / $total, 1), 'review_count' => $total + 64])])->save();
    }

    private function social(): void
    {
        $org = $this->rivera;
        $images = [
            ['van', 'New look for the fleet', 'Rivera Plumbing & Heating', [14, 116, 144], 'Our newly wrapped service van in front of the Austin shop'],
            ['tankless', 'Tankless installs', 'Endless hot water', [234, 88, 12], 'Technician installing a tankless water heater in a garage'],
            ['team', 'Meet the team', 'Family-owned since 2009', [22, 101, 52], 'The Rivera team standing in front of the shop'],
            ['winter', 'Freeze warning tips', 'Protect your pipes', [30, 64, 175], 'Insulated outdoor faucet cover on a frosty morning'],
        ];
        $media = [];
        foreach ($images as [$slug, $title, $subtitle, $rgb, $alt]) {
            $path = 'social/'.$org->ulid.'/'.Str::ulid().'.jpg';
            [$size, $w, $h] = $this->image($path, $title, $subtitle, $rgb);
            $media[$slug] = MediaAsset::create(['organization_id' => $org->id, 'disk' => 'local', 'path' => $path, 'original_name' => $slug.'.jpg', 'mime' => 'image/jpeg',
                'size_bytes' => $size, 'width' => $w, 'height' => $h, 'alt_text' => $alt, 'uploaded_by_user_id' => $this->owner->id]);
            $media[$slug]->forceFill(['created_at' => $this->now->subDays(mt_rand(20, 80))])->saveQuietly();
        }

        $fb = $this->accounts['facebook'];
        $ig = $this->accounts['instagram'];
        $gbp = $this->accounts['google'];
        $li = $this->accounts['linkedin'];
        $posts = $this->postPlan($fb, $ig, $gbp, $li);
        foreach ($posts as $p => [$status, $days, $body, $accounts, $image, $link]) {
            $created = $days === null ? $this->now->subDays(1) : ($days < 0 ? $this->now->addDays($days)->subDays(2) : $this->now->subDays(mt_rand(1, 3)));
            $when = $days === null ? null : $this->localAt(-$days, 10, 0);
            $team = $status === 'in_review';
            $post = SocialPost::create([
                'organization_id' => $org->id, 'body' => $body, 'link_url' => $link, 'status' => $status, 'scheduled_at' => $when,
                'published_at' => in_array($status, ['published', 'partly_published'], true) ? $when : null,
                'source' => $team ? 'team' : 'manual', 'created_by_user_id' => $team ? $this->admin->id : $this->pick([$this->owner, $this->manager])->id,
                'submitted_at' => $team ? $created : null,
                'approved_by_user_id' => in_array($status, ['published', 'partly_published', 'scheduled'], true) ? $this->owner->id : null,
                'approved_at' => in_array($status, ['published', 'partly_published', 'scheduled'], true) ? $created->addHours(2) : null,
            ]);
            $post->forceFill(['created_at' => $created, 'updated_at' => $when && $when->lessThan($this->now) ? $when : $created])->saveQuietly();
            if ($image) {
                $post->media()->attach($media[$image]->id, ['position' => 0]);
            }
            foreach ($accounts as $a => $account) {
                $targetStatus = match ($status) {
                    'published' => SocialPostTarget::PUBLISHED,
                    'partly_published' => $account->network === SocialNetwork::LinkedIn ? SocialPostTarget::FAILED : SocialPostTarget::PUBLISHED,
                    'failed' => SocialPostTarget::FAILED,
                    default => SocialPostTarget::PENDING,
                };
                $external = $targetStatus === SocialPostTarget::PUBLISHED ? (string) (9100000000 + $p * 1000 + $a) : null;
                SocialPostTarget::create([
                    'social_post_id' => $post->id, 'social_account_id' => $account->id, 'status' => $targetStatus,
                    'attempts' => $targetStatus === SocialPostTarget::FAILED ? 3 : ($targetStatus === SocialPostTarget::PUBLISHED ? 1 : 0),
                    'next_attempt_at' => $targetStatus === SocialPostTarget::PENDING ? $when : null,
                    'external_id' => $external,
                    'external_url' => $external ? match ($account->network) {
                        SocialNetwork::Facebook => 'https://www.facebook.com/riveraplumbingatx/posts/'.$external,
                        SocialNetwork::Instagram => 'https://www.instagram.com/p/C'.strtoupper(substr(md5($external), 0, 10)).'/',
                        SocialNetwork::GoogleBusiness => 'https://business.google.com/posts/l/'.$external,
                        SocialNetwork::LinkedIn => 'https://www.linkedin.com/feed/update/urn:li:share:'.$external,
                    } : null,
                    'published_at' => $external ? $when : null,
                    'last_error' => match (true) {
                        $targetStatus !== SocialPostTarget::FAILED => null,
                        $account->network === SocialNetwork::Instagram => 'Instagram refused the photo: the aspect ratio must be between 4:5 and 1.91:1. Crop the photo and try again.',
                        default => 'LinkedIn says the access token has expired. Reconnect LinkedIn and publish again.',
                    },
                    'options' => $account->network === SocialNetwork::GoogleBusiness && $link ? ['button' => 'LEARN_MORE'] : null,
                ]);
            }
        }
    }

    /**
     * A simple branded square photo for the media library.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     * @return array{0: int, 1: int, 2: int}
     */
    private function image(string $path, string $title, string $subtitle, array $rgb): array
    {
        $size = 1080;
        $image = imagecreatetruecolor($size, $size);
        for ($y = 0; $y < $size; $y += 6) {
            $f = $y / $size;
            $color = imagecolorallocate($image, (int) ($rgb[0] * (1 - $f * 0.55)), (int) ($rgb[1] * (1 - $f * 0.55)), (int) ($rgb[2] * (1 - $f * 0.55)));
            imagefilledrectangle($image, 0, $y, $size, $y + 6, (int) $color);
        }
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $soft = (int) imagecolorallocatealpha($image, 255, 255, 255, 100);
        imagefilledellipse($image, 860, 240, 420, 420, $soft);
        imagefilledellipse($image, 160, 900, 300, 300, $soft);

        foreach ([[$title, 5, 120, 430, 7], [$subtitle, 5, 120, 560, 4], ['riveraplumbing.example', 3, 120, 940, 3]] as [$text, $font, $x, $y, $scale]) {
            $w = imagefontwidth($font) * strlen($text);
            $h = imagefontheight($font);
            $small = imagecreatetruecolor($w, $h);
            imagesavealpha($small, true);
            imagefill($small, 0, 0, (int) imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagestring($small, $font, 0, 0, $text, (int) imagecolorallocate($small, 255, 255, 255));
            imagecopyresampled($image, $small, $x, $y, 0, 0, $w * $scale, $h * $scale, $w, $h);
            imagedestroy($small);
        }
        imagefilledrectangle($image, 120, 400, 300, 408, $white);

        ob_start();
        imagejpeg($image, null, 86);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put($path, $bytes);
        $this->files[] = $path;

        return [strlen($bytes), $size, $size];
    }

    private Website $website;

    private function website(): void
    {
        $base = 'https://riveraplumbing.example';
        $pages = [
            ['/', 'Plumber in Austin & Round Rock | Rivera Plumbing & Heating', 'Licensed Austin plumbers since 2009. Drain cleaning, water heaters, leak repair and 24/7 emergency service. Upfront prices — call or book online.', 'Austin\'s Family Plumbing & Heating Company'],
            ['/services', 'Plumbing Services in Austin | Rivera Plumbing & Heating', 'Every plumbing and heating service we offer in Austin, Round Rock and Pflugerville, with prices and what to expect from a visit.', 'Our Plumbing & Heating Services'],
            ['/water-heaters', 'Water Heater Repair & Installation Austin | Rivera', 'Same-week water heater repair and replacement in Austin: tank and tankless, gas and electric. Free replacement estimates.', 'Water Heater Repair & Installation'],
            ['/drain-cleaning', 'Drain Cleaning Austin — $149 Flat Rate | Rivera Plumbing', 'Slow or blocked drains cleared for a flat $149. Kitchen, bath and laundry drains in Austin and Round Rock.', 'Professional Drain Cleaning'],
            ['/emergency-plumber', '24/7 Emergency Plumber Austin | Rivera Plumbing & Heating', 'Burst pipe or sewage backup? A Rivera plumber on site within 90 minutes, day or night, anywhere in Austin and Round Rock.', '24/7 Emergency Plumber'],
            ['/about', 'About Us | Rivera Plumbing & Heating', null, 'Family-Owned Since 2009'],
            ['/contact', 'Contact', 'Call (512) 555-0147.', 'Contact Rivera Plumbing'],
        ];
        $findings = [
            ['key' => 'https', 'status' => 'good', 'title' => 'Your site uses HTTPS', 'detail' => '', 'pages' => []],
            ['key' => 'titles', 'status' => 'good', 'title' => 'Every page has a good title', 'detail' => '', 'pages' => []],
            ['key' => 'descriptions', 'status' => 'warning', 'title' => 'Some pages have no description', 'detail' => 'The description is the grey text under your title in search results. Write one or two sentences (50–160 characters) per page saying what you do, where, and how to book.', 'pages' => [$base.'/about']],
            ['key' => 'description_length', 'status' => 'warning', 'title' => 'Some descriptions are too short or too long', 'detail' => 'Aim for 50–160 characters so search engines show the whole description.', 'pages' => [$base.'/contact']],
            ['key' => 'headings', 'status' => 'good', 'title' => 'Every page has one main heading', 'detail' => '', 'pages' => []],
            ['key' => 'mobile', 'status' => 'good', 'title' => 'Your site is set up for phones', 'detail' => '', 'pages' => []],
            ['key' => 'speed', 'status' => 'good', 'title' => 'Your home page answers quickly', 'detail' => '', 'pages' => []],
            ['key' => 'alt_text', 'status' => 'warning', 'title' => '6 images have no description', 'detail' => 'Describe each photo in its "alt text" (for example "Technician repairing a water heater"). It helps people using screen readers and helps your photos appear in image search.', 'pages' => [$base.'/', $base.'/about']],
            ['key' => 'broken_links', 'status' => 'good', 'title' => 'No broken links found', 'detail' => '', 'pages' => []],
            ['key' => 'sitemap', 'status' => 'good', 'title' => 'Your site has a sitemap', 'detail' => '', 'pages' => []],
            ['key' => 'indexing', 'status' => 'good', 'title' => 'Search engines are allowed to list your site', 'detail' => '', 'pages' => []],
            ['key' => 'structured_data', 'status' => 'good', 'title' => 'Search engines can read your business details', 'detail' => '', 'pages' => []],
            ['key' => 'nap', 'status' => 'good', 'title' => 'Your name, phone and area match your business profile', 'detail' => '', 'pages' => []],
            ['key' => 'snippet', 'status' => 'good', 'title' => 'SureHelp is on your site', 'detail' => '', 'pages' => []],
        ];

        $this->website = Website::create([
            'organization_id' => $this->rivera->id, 'url' => $base.'/', 'host' => 'riveraplumbing.example', 'added_by' => $this->owner->id,
            'verified_at' => $this->now->subDays(101), 'verified_via' => 'meta', 'last_checked_at' => $this->now->subDays(4)->setTime(4, 12),
            'health_score' => 85,
            'health' => [
                'checked_pages' => array_map(fn (array $p) => $base.$p[0], $pages),
                'findings' => $findings,
                'pages' => array_map(fn (array $p) => ['url' => $base.$p[0], 'title' => $p[1], 'description' => $p[2], 'h1' => $p[3]], $pages),
            ],
        ]);
        $this->website->forceFill(['created_at' => $this->now->subDays(102), 'updated_at' => $this->now->subDays(4)])->saveQuietly();

        foreach ([[101, 55, 2, 3, 5], [71, 65, 2, 1, 6], [41, 75, 1, 2, 7], [21, 80, 1, 1, 7], [4, 85, 0, 3, 7]] as [$ago, $score, $problems, $warnings, $checked]) {
            WebsiteCheck::create(['website_id' => $this->website->id, 'organization_id' => $this->rivera->id, 'checked_at' => $this->now->subDays($ago)->setTime(4, 12),
                'health_score' => $score, 'problems' => $problems, 'warnings' => $warnings, 'pages_checked' => $checked]);
        }

        foreach (['dental' => 'https://brightsmile.example/', 'law' => 'https://lakesidelaw.example/'] as $key => $url) {
            Website::create(['organization_id' => $this->orgs[$key]->id, 'url' => $url, 'host' => (string) parse_url($url, PHP_URL_HOST), 'added_by' => $this->specs[$key]['owner']->id]);
        }
    }

    /** @var array<string, string> */
    private array $seoTasks = [];

    private function seo(): void
    {
        $org = $this->rivera;
        $base = 'https://riveraplumbing.example';
        $tasks = [
            'keyword_plan' => [64, 60, 'approved', [
                'summary' => 'Rivera already ranks on page two for most "plumber austin" searches. The quickest wins are dedicated pages for water heaters, drain cleaning and emergencies, plus Round Rock and Pflugerville pages, because nearby competitors have thin local content.',
                'keywords' => [
                    ['keyword' => 'emergency plumber austin', 'intent' => 'urgent', 'priority' => 'high', 'target_page' => $base.'/emergency-plumber', 'why' => 'High intent and high value; you offer 24/7 service but had no page for it.'],
                    ['keyword' => 'water heater repair austin', 'intent' => 'local', 'priority' => 'high', 'target_page' => $base.'/water-heaters', 'why' => 'Your most profitable repair; current page title doesn\'t mention it.'],
                    ['keyword' => 'drain cleaning austin', 'intent' => 'local', 'priority' => 'high', 'target_page' => $base.'/drain-cleaning', 'why' => 'Flat $149 price is a strong differentiator to show in results.'],
                    ['keyword' => 'plumber round rock tx', 'intent' => 'local', 'priority' => 'high', 'target_page' => 'New page: Plumber in Round Rock', 'why' => 'You have a branch there but no local page.'],
                    ['keyword' => 'tankless water heater installation', 'intent' => 'research', 'priority' => 'medium', 'target_page' => $base.'/water-heaters', 'why' => 'Growing search volume; pairs with your Rinnai and Navien installs.'],
                    ['keyword' => 'slab leak repair austin', 'intent' => 'local', 'priority' => 'medium', 'target_page' => 'New page: Slab Leak Detection', 'why' => 'Common in Austin clay soil; high-ticket jobs.'],
                    ['keyword' => 'how much does a water heater cost', 'intent' => 'research', 'priority' => 'medium', 'target_page' => 'New blog post', 'why' => 'Brings in buyers early; link to the free estimate.'],
                    ['keyword' => 'plumber pflugerville', 'intent' => 'local', 'priority' => 'medium', 'target_page' => 'New page: Plumber in Pflugerville', 'why' => 'Served from Round Rock; few strong competitors.'],
                    ['keyword' => 'sewer camera inspection austin', 'intent' => 'local', 'priority' => 'low', 'target_page' => $base.'/services', 'why' => 'Home buyers search this before closing.'],
                    ['keyword' => 'rivera plumbing', 'intent' => 'brand', 'priority' => 'low', 'target_page' => $base.'/', 'why' => 'Protect your name; make sure the home page title includes it.'],
                    ['keyword' => 'furnace tune up austin', 'intent' => 'local', 'priority' => 'low', 'target_page' => $base.'/services', 'why' => 'Seasonal; worth a Google post every October.'],
                    ['keyword' => 'best plumber near me', 'intent' => 'comparison', 'priority' => 'medium', 'target_page' => $base.'/', 'why' => 'Reviews and the Google Business Profile matter most here.'],
                ],
                'content_ideas' => [
                    ['title' => 'Tank vs tankless: what makes sense in an Austin home?', 'why' => 'Answers the most common research question and leads to a free estimate.'],
                    ['title' => 'What to do in the first five minutes of a burst pipe', 'why' => 'Shareable before freezes; ranks for emergency searches.'],
                    ['title' => 'Round Rock plumbing: our branch, our team, our prices', 'why' => 'A real local page for the second location.'],
                    ['title' => 'Why Austin homes get slab leaks (and how we find them)', 'why' => 'Builds trust for a high-ticket service.'],
                ],
            ], $this->manager],
            'page_meta' => [38, 35, 'approved', ['pages' => [
                ['url' => $base.'/', 'title' => 'Plumber in Austin & Round Rock | Rivera Plumbing & Heating', 'description' => 'Licensed Austin plumbers since 2009. Drain cleaning, water heaters, leak repair and 24/7 emergency service. Upfront prices — call or book online.', 'h1' => 'Austin\'s Family Plumbing & Heating Company', 'why' => 'Leads with the service and both towns; the old title was just "Home".'],
                ['url' => $base.'/water-heaters', 'title' => 'Water Heater Repair & Installation Austin | Rivera', 'description' => 'Same-week water heater repair and replacement in Austin: tank and tankless, gas and electric. Free replacement estimates.', 'h1' => 'Water Heater Repair & Installation', 'why' => 'Targets the highest-value keyword in the plan.'],
                ['url' => $base.'/drain-cleaning', 'title' => 'Drain Cleaning Austin — $149 Flat Rate | Rivera Plumbing', 'description' => 'Slow or blocked drains cleared for a flat $149. Kitchen, bath and laundry drains in Austin and Round Rock.', 'h1' => 'Professional Drain Cleaning', 'why' => 'Shows the flat price right in search results.'],
                ['url' => $base.'/services', 'title' => 'Plumbing Services in Austin | Rivera Plumbing & Heating', 'description' => 'Every plumbing and heating service we offer in Austin, Round Rock and Pflugerville, with prices and what to expect from a visit.', 'h1' => 'Our Plumbing & Heating Services', 'why' => 'Was missing a description entirely.'],
                ['url' => $base.'/about', 'title' => 'About Rivera Plumbing & Heating | Family-Owned Since 2009', 'description' => 'Meet the family and the licensed team behind Rivera Plumbing & Heating, serving Austin homes since 2009.', 'h1' => 'Family-Owned Since 2009', 'why' => 'Adds trust signals and a description.'],
            ]], $this->owner],
            'local_page' => [20, null, 'approved', [
                'slug' => 'plumber-round-rock-tx', 'title' => 'Plumber in Round Rock, TX | Rivera Plumbing & Heating', 'description' => 'Licensed plumbers based in Round Rock: drain cleaning, water heaters, leak repair and 24/7 emergencies across Williamson County.',
                'h1' => 'Your Round Rock Plumber', 'intro' => 'Rivera Plumbing & Heating has served Round Rock from our branch on N Mays St for years. When something leaks, clogs or stops heating, a local, licensed plumber is on the way — usually the same day.',
                'sections' => [
                    ['heading' => 'Plumbing services in Round Rock', 'body' => 'Drain cleaning ($149 flat), water heater repair and replacement, leak detection, toilet and faucet repair, sewer camera inspections and heating tune-ups.'],
                    ['heading' => 'Areas we cover from Round Rock', 'body' => 'Round Rock, Pflugerville, Georgetown, Cedar Park, Leander and Hutto.'],
                    ['heading' => 'Why neighbours choose Rivera', 'body' => 'Family-owned since 2009, upfront written prices before any work, and technicians who clean up after themselves.'],
                    ['heading' => 'What happens when you book', 'body' => 'You get a confirmation, a text when the technician is on the way, and a written price before work starts.'],
                ],
                'faq' => [
                    ['question' => 'Do you charge a trip fee in Round Rock?', 'answer' => 'No trip fee. Repairs start with a $79 diagnostic visit, credited if you go ahead with the repair.'],
                    ['question' => 'Can you come today?', 'answer' => 'Usually yes for repairs booked before noon, and 24/7 for emergencies with the $249 emergency call-out.'],
                    ['question' => 'Are you licensed?', 'answer' => 'Yes — every job is done under master plumber license TX M-41872.'],
                ],
                'call_to_action' => 'Call (512) 555-0148 or book online — a Round Rock plumber can usually be there today.',
            ], $this->owner],
            'faq' => [12, null, 'approved', ['faq' => [
                ['question' => 'How much does a plumber cost in Austin?', 'answer' => 'Most repairs start with a $79 diagnostic visit, which is credited against the repair if you go ahead. Drain cleaning is a flat $149 and toilet repairs $129. For bigger jobs like water heater replacements you get a free written estimate before any work starts.'],
                ['question' => 'Do you offer 24/7 emergency plumbing?', 'answer' => 'Yes. Burst pipes, sewage backups and floods are covered day and night, weekends and holidays, with a $249 emergency call-out. A technician is usually on site within 90 minutes anywhere in Austin and Round Rock.'],
                ['question' => 'Should I repair or replace my water heater?', 'answer' => 'If the tank is under 8–10 years old and the problem is a part like a thermostat or element, repair is usually worth it. Rust at the base, leaks from the tank itself, or a unit older than 12 years usually mean replacement. Our technician will tell you honestly.'],
                ['question' => 'Do you install tankless water heaters?', 'answer' => 'Yes, we install Rinnai and Navien tankless units. Some older homes need a larger gas line or new venting, which we check during the free estimate.'],
                ['question' => 'Which areas do you serve?', 'answer' => 'Austin, Round Rock, Pflugerville, Cedar Park, Georgetown, Leander, Lakeway and Buda, from our shops on South Lamar and in Round Rock.'],
                ['question' => 'Are you licensed and insured?', 'answer' => 'Yes. Rivera Plumbing & Heating is fully insured and all work is done under master plumber license TX M-41872.'],
            ]], $this->owner],
            'gbp_posts' => [5, null, null, ['posts' => [
                ['title' => 'Heating tune-ups are back', 'body' => 'Cold fronts are coming. Our $99 heating tune-up includes a full safety check and a carbon-monoxide test, so your furnace is ready before you need it. Book online or give us a call.', 'button' => 'BOOK'],
                ['title' => 'Protect your pipes this winter', 'body' => 'Before the first freeze: disconnect garden hoses, cover outdoor faucets and know where your main shut-off valve is. If a pipe does burst, our emergency line is open 24/7.', 'button' => 'CALL'],
                ['title' => 'Why families choose Rivera', 'body' => 'Family-owned since 2009, licensed master plumbers, and a written price before any work starts. That is how we have earned more than 300 five-star reviews across Austin and Round Rock.', 'button' => 'LEARN_MORE'],
                ['title' => 'Hosting for the holidays?', 'body' => 'Kitchen sinks and guest bathrooms work overtime in November. Book a $149 drain cleaning now and enjoy a stress-free Thanksgiving.', 'button' => 'BOOK'],
            ]], $this->owner],
        ];

        foreach ($tasks as $type => [$ago, $appliedAgo, $review, $output, $by]) {
            $created = $this->now->subDays($ago)->setTime(15, 10);
            $input = match ($type) {
                'page_meta' => ['pages' => array_map(fn (array $p) => array_intersect_key($p, array_flip(['url', 'title', 'description', 'h1'])), $this->website->health['pages'] ?? [])],
                'local_page' => ['service' => 'Plumbing', 'area' => 'Round Rock'],
                'faq' => ['topic' => 'pricing and emergencies'],
                default => [],
            };
            $task = SeoTask::create([
                'organization_id' => $org->id, 'website_id' => $this->website->id, 'type' => $type, 'status' => 'done', 'source' => $type === 'keyword_plan' || $type === 'gbp_posts' ? 'autopilot' : 'client',
                'input' => $input, 'output' => $output, 'model' => 'claude-opus-5-5', 'input_tokens' => mt_rand(9000, 16000), 'output_tokens' => mt_rand(1800, 4200),
                'review' => $review, 'applied_at' => $appliedAgo !== null ? $this->now->subDays($appliedAgo)->setTime(16, 40) : null,
                'applied_by_user_id' => $appliedAgo !== null ? $this->admin->id : null, 'requested_by_user_id' => $by->id,
            ]);
            $task->forceFill(['created_at' => $created, 'updated_at' => $created->addMinutes(1)])->saveQuietly();
            $this->seoTasks[$type] = $task->ulid;
        }

        // Search Console: about 200 days, flat before SEO work and climbing after it.
        $rows = [];
        for ($d = 202; $d >= 2; $d--) {
            $date = $this->day($d);
            $p = $d > 60 ? 0.0 : min(1.0, (60 - $d) / 52);
            $p = $p * $p * (3 - 2 * $p);
            $weekend = in_array($date->dayOfWeek, [0, 6], true) ? 0.7 : 1.0;
            $impressions = (int) round((150 + 200 * $p) * $weekend * (0.88 + mt_rand(0, 24) / 100));
            $clicks = (int) max(0, round((3 + 7 * $p) * $weekend * (0.6 + mt_rand(0, 80) / 100)));
            $position = round(17.2 - 9.2 * $p + (mt_rand(-80, 80) / 100), 2);
            $rows[] = ['organization_id' => $org->id, 'date' => $date->toDateString(), 'clicks' => $clicks, 'impressions' => $impressions, 'position' => $position];
        }
        SeoDailyMetric::insert($rows);

        SearchConsoleConnection::create([
            'organization_id' => $org->id, 'connected_by_user_id' => $this->owner->id, 'account_email' => 'elena@riveraplumbing.example',
            'access_token' => 'demo-gsc-access-'.Str::random(32), 'refresh_token' => 'demo-gsc-refresh-'.Str::random(32), 'token_expires_at' => $this->now->addMinutes(50),
            'sites' => [['url' => 'sc-domain:riveraplumbing.example', 'permission' => 'siteOwner'], ['url' => 'https://riveraplumbing.example/', 'permission' => 'siteFullUser']],
            'site_url' => 'sc-domain:riveraplumbing.example', 'status' => 'active', 'last_synced_at' => $this->now->subMinutes(35),
            'top_queries' => [
                ['query' => 'rivera plumbing', 'clicks' => 61, 'impressions' => 240, 'position' => 1.2],
                ['query' => 'plumber austin', 'clicks' => 34, 'impressions' => 2210, 'position' => 8.4],
                ['query' => 'emergency plumber austin', 'clicks' => 29, 'impressions' => 980, 'position' => 6.1],
                ['query' => 'water heater repair austin', 'clicks' => 24, 'impressions' => 1130, 'position' => 7.3],
                ['query' => 'drain cleaning austin', 'clicks' => 19, 'impressions' => 760, 'position' => 5.8],
                ['query' => 'plumber round rock', 'clicks' => 17, 'impressions' => 690, 'position' => 6.9],
                ['query' => 'tankless water heater installation austin', 'clicks' => 11, 'impressions' => 540, 'position' => 9.7],
                ['query' => 'plumber near me', 'clicks' => 9, 'impressions' => 1890, 'position' => 14.2],
                ['query' => 'slab leak repair austin', 'clicks' => 6, 'impressions' => 310, 'position' => 11.5],
                ['query' => 'sewer camera inspection austin', 'clicks' => 4, 'impressions' => 150, 'position' => 8.8],
            ],
        ]);
        $this->rivera->forceFill(['seo_token_allowance' => 300000])->save();
    }

    /**
     * Live calls (D60): SureHelp lines for three businesses, and a week of Rivera's phone calls
     * behind the calls agents logged, plus a few nobody could answer (call-back tasks).
     */
    private function phoneLines(): void
    {
        $this->travel($this->now->subDays(10));
        $lines = [];
        foreach (array_values(array_filter($this->orgs, fn (Organization $o) => $o->id !== $this->rivera->id)) as $i => $org) {
            if ($i < 2) {
                $lines[] = PhoneNumber::create(['organization_id' => $org->id, 'provider' => 'fake', 'provider_sid' => 'PNdemo'.($i + 1), 'number' => '+1512555020'.($i + 1), 'label' => 'Main line']);
            }
        }
        $line = PhoneNumber::create(['organization_id' => $this->rivera->id, 'provider' => 'fake', 'provider_sid' => 'PNdemo0', 'number' => '+15125550200',
            'label' => 'Main line', 'transfer_number' => '+15125550102', 'text_back' => true]);
        $this->travel($this->now);

        $logs = CallLog::withoutGlobalScopes()->where('organization_id', $this->rivera->id)->where('created_at', '>=', $this->now->subDays(7))
            ->whereIn('user_id', [$this->john->id, $this->mike->id])->get();
        foreach ($logs as $log) {
            $wait = random_int(3, 18);
            $talk = random_int(70, 420);
            $started = CarbonImmutable::parse($log->created_at)->subSeconds($wait + $talk + random_int(20, 90));
            VoiceCall::create([
                'organization_id' => $this->rivera->id, 'phone_number_id' => $line->id, 'provider' => 'fake', 'provider_call_sid' => 'CAdemo'.Str::lower(Str::random(26)),
                'from_number' => Phone::normalize((string) $log->caller_phone), 'to_number' => $line->number, 'status' => VoiceCall::COMPLETED,
                'offered_to' => [$this->john->id, $this->mike->id], 'answered_by_user_id' => $log->user_id, 'customer_id' => $log->customer_id, 'call_log_id' => $log->id,
                'started_at' => $started, 'answered_at' => $started->addSeconds($wait), 'ended_at' => $started->addSeconds($wait + $talk),
                'wait_seconds' => $wait, 'duration_seconds' => $talk, // no recordings: the demo phone has no audio
            ]);
        }

        foreach ([[2, 7, 52, VoiceCall::MISSED, '+15125550188'], [1, 18, 41, VoiceCall::VOICEMAIL, '+15125550176'], [0, 7, 58, VoiceCall::MISSED, '+15125550163']] as [$daysAgo, $hour, $minute, $status, $from]) {
            $started = $this->localAt($daysAgo, $hour, $minute);
            $this->travel($started->addMinutes(1));
            $call = VoiceCall::create([
                'organization_id' => $this->rivera->id, 'phone_number_id' => $line->id, 'provider' => 'fake', 'provider_call_sid' => 'CAdemo'.Str::lower(Str::random(26)),
                'from_number' => $from, 'to_number' => $line->number, 'status' => $status, 'offered_to' => [], 'started_at' => $started, 'ended_at' => $started->addSeconds(48),
                'transferred_to' => '+15125550102',
            ]);
            FinishVoiceCall::dispatchSync($call->id);
        }
        $this->travel($this->now);

        // This morning's missed caller was texted back and answered by text (D61).
        $missed = VoiceCall::withoutGlobalScopes()->where('from_number', '+15125550163')->first();
        if ($missed) {
            $this->travel(CarbonImmutable::parse($missed->started_at)->addMinutes(1));
            $texting = app(Texting::class);
            $texting->sendAutomatic($this->rivera, '+15125550163', str_replace('{business}', 'Rivera Plumbing & Heating', PhoneNumber::DEFAULT_TEXT_BACK), 'missed_call');
            $missed->forceFill(['texted_back_at' => now()])->save();
            $this->travel(CarbonImmutable::parse($missed->started_at)->addMinutes(6));
            app(ReceiveMessage::class)->handle($this->rivera, InboxChannel::Sms, [
                'channel_key' => $line->number, 'thread' => '+15125550163', 'phone' => '+15125550163', 'handle' => '(512) 555-0163',
                'external_id' => 'SMdemo1', 'body' => 'Hi, our kitchen sink is backing up. Can someone come out tomorrow morning?',
            ]);
            $this->travel($this->now);
        }

        $this->summary[] = 'Phone lines: '.(count($lines) + 1).', with '.VoiceCall::withoutGlobalScopes()->count().' calls';
    }

    private function calendar(): void
    {
        $connection = CalendarConnection::create([
            'organization_id' => $this->rivera->id, 'connected_by_user_id' => $this->owner->id, 'provider' => 'google', 'account_email' => 'elena@riveraplumbing.example',
            'access_token' => 'demo-calendar-access-'.Str::random(32), 'refresh_token' => 'demo-calendar-refresh-'.Str::random(32), 'token_expires_at' => $this->now->addMinutes(45),
            'scopes' => 'https://www.googleapis.com/auth/calendar',
            'calendars' => [
                ['id' => 'rivera-jobs@group.calendar.google.com', 'name' => 'Rivera Jobs', 'primary' => false, 'can_write' => true],
                ['id' => 'elena@riveraplumbing.example', 'name' => 'Elena Rivera', 'primary' => true, 'can_write' => true],
                ['id' => 'en.usa#holiday@group.v.calendar.google.com', 'name' => 'Holidays in United States', 'primary' => false, 'can_write' => false],
            ],
            'write_calendar_id' => 'rivera-jobs@group.calendar.google.com',
            'busy_calendar_ids' => ['elena@riveraplumbing.example'],
            'status' => 'active', 'last_synced_at' => $this->now->subMinutes(8),
        ]);
        $connection->forceFill(['created_at' => $this->now->subDays(127)])->saveQuietly();

        // Elena's own commitments block bookable time.
        foreach ([[0, 12, 0, 60], [1, 7, 0, 90], [2, 16, 0, 120], [3, 12, 30, 60], [6, 9, 0, 180], [8, 13, 0, 60], [10, 15, 0, 90], [13, 8, 0, 240]] as $i => [$day, $hour, $minute, $length]) {
            $start = $this->localAt(-$day, $hour, $minute);
            CalendarBusyBlock::create(['organization_id' => $this->rivera->id, 'calendar_connection_id' => $connection->id, 'calendar_id' => 'elena@riveraplumbing.example',
                'external_event_id' => 'evt_'.Str::lower(Str::random(18)).$i, 'starts_at' => $start, 'ends_at' => $start->addMinutes($length)]);
        }
        CalendarBusyBlock::create(['organization_id' => $this->rivera->id, 'calendar_connection_id' => $connection->id, 'calendar_id' => 'elena@riveraplumbing.example',
            'external_event_id' => 'evt_trade_show', 'starts_at' => $this->day(-11)->utc(), 'ends_at' => $this->day(-12)->utc(), 'all_day' => true]);
    }

    private function schedules(): void
    {
        if (! $this->sandbox) {
            AgentDutySchedule::query()->delete();
        }
        $start = $this->now->startOfWeek()->subWeeks(2);
        for ($d = 0; $d < 35; $d++) {
            $day = $start->addDays($d);
            $isToday = $day->isSameDay($this->now);
            foreach ([[$this->john, 13, 'morning', 'Morning shift', [0]], [$this->mike, 17, 'afternoon', 'Afternoon shift', [0, 1]]] as [$agent, $hour, $type, $title, $off]) {
                $weekend = in_array($day->dayOfWeek, $off, true);
                if ($isToday) {
                    // Both agents are on shift right now, whatever time the demo is rebuilt.
                    $from = $this->now->subHours($agent->is($this->john) ? 3 : 1)->startOfHour();
                    AgentDutySchedule::create(['agent_id' => $agent->id, 'title' => $title, 'start_datetime' => $from, 'end_datetime' => $from->addHours(8), 'shift_type' => $type,
                        'description' => 'Rivera Plumbing, Bright Smile Dental and Lakeside Law queues.', 'is_active' => true]);

                    continue;
                }
                if ($weekend) {
                    AgentDutySchedule::create(['agent_id' => $agent->id, 'title' => 'Day off', 'start_datetime' => $day->setTime(0, 0), 'end_datetime' => $day->setTime(23, 59), 'shift_type' => 'off', 'is_active' => true]);

                    continue;
                }
                AgentDutySchedule::create(['agent_id' => $agent->id, 'title' => $title, 'start_datetime' => $day->setTime($hour, 0), 'end_datetime' => $day->setTime($hour + 8, 0), 'shift_type' => $type,
                    'description' => $type === 'morning' ? 'Opening shift: Rivera, Bright Smile and Lakeside queues.' : 'Afternoon and evening: Rivera after-hours and Green Valley HVAC.', 'is_active' => true]);
            }
        }
        $swapShift = AgentDutySchedule::query()->where('agent_id', $this->mike->id)->where('shift_type', 'afternoon')->where('start_datetime', '>', $this->now->addDays(2))->orderBy('start_datetime')->first();
        if ($swapShift) {
            ShiftRequest::create(['agent_id' => $this->mike->id, 'type' => ShiftRequest::SWAP, 'shift_id' => $swapShift->id, 'swap_with_id' => $this->john->id, 'reason' => 'Parent-teacher conference that afternoon — happy to take John\'s Saturday in return.']);
        }
    }

    private function enquiries(): void
    {
        $rows = [
            [9, 'Laura Jennings', 'laura@jenningsroofing.example', '(512) 555-0231', 'Jennings Roofing', 'sales', 'We miss a lot of calls while on roofs. How does pricing work for about 300 calls a month? Do your agents book estimates into Google Calendar?', true, true],
            [6, 'Omar Haddad', 'omar@haddadautocare.example', '(737) 555-0244', 'Haddad Auto Care', 'demo', 'Could we get a demo next week? Two locations in North Austin, mostly appointment calls.', true, true],
            [4, 'Grace Kim', 'grace.kim@kimfamilydental.example', '(512) 555-0252', 'Kim Family Dental', 'general', 'Do your agents handle Spanish-speaking patients? Around 30% of our callers prefer Spanish.', false, false],
            [3, 'Ben Whitaker', 'ben@whitakerlaw.example', null, 'Whitaker Law', 'enterprise', 'We are a 12-attorney firm with three offices. Interested in after-hours intake with conflict checks.', false, false],
            [1, 'Sandra Okafor', 'sandra@brightpathcleaning.example', '(512) 555-0266', 'BrightPath Cleaning', 'sales', 'Saw you on Google. Can you also answer our Facebook messages and Instagram DMs?', true, false],
            [0, 'Mateo Ruiz', 'mateo.ruiz@example.com', '(737) 555-0271', null, 'support', 'I tried to book a plumber through a website chat and never got a confirmation email. Who do I contact?', false, false],
        ];
        foreach ($rows as [$ago, $name, $email, $phone, $company, $type, $message, $sms, $handled]) {
            $created = $ago === 0 ? $this->now->subHours(2) : $this->localAt($ago, mt_rand(9, 17), mt_rand(0, 59));
            $row = ContactSubmission::create(['name' => $name, 'email' => $email, 'phone' => $phone, 'company' => $company, 'inquiry_type' => $type, 'message' => $message, 'sms_consent' => $sms, 'ip_address' => '203.0.113.'.mt_rand(10, 250)]);
            $row->forceFill(['created_at' => $created, 'updated_at' => $created, 'handled_at' => $handled ? $created->addHours(5) : null, 'handled_by' => $handled ? $this->admin->id : null])->saveQuietly();
        }
    }

    /** Every demo account signs straight in: terms accepted, email verified, two-step set up for staff. */
    private function accountGates(): void
    {
        $legal = app(LegalDocuments::class);
        $codes = ['demo1-codes', 'demo2-codes', 'demo3-codes', 'demo4-codes', 'demo5-codes', 'demo6-codes', 'demo7-codes', 'demo8-codes'];
        $hashed = array_map(fn (string $c) => Hash::make($c, ['rounds' => 4]), $codes);

        $emails = $this->sandbox ? array_column(self::SANDBOX_ACCOUNTS, 1) : ['admin@surehelpsolution.com', 'agent@surehelpsolution.com', 'mike.agent@surehelpsolution.com', 'client@surehelpsolution.com', 'sarah.client@surehelpsolution.com'];
        $emails = [...$emails, 'marcus@riveraplumbing.example', 'sofia@riveraplumbing.example', 'david@lakesidelaw.example', 'tom@greenvalleyhvac.example', 'priya@sunriseyoga.example'];
        $users = User::query()->whereIn('email', $emails)->get();
        foreach ($users as $user) {
            $this->travel($this->now->subDays(min(100, max(1, (int) $user->created_at?->diffInDays($this->now)))));
            $legal->accept($user);
            $this->travel($this->now);
            $changes = ['is_active' => true, 'must_change_password' => false, 'email_verified_at' => $user->email_verified_at ?? $user->created_at ?? $this->now, 'last_login_at' => $this->now->subHours(mt_rand(2, 30))];
            if ($user->requiresTwoFactor()) {
                // Sandbox accounts can't sign in anyway; they never get the published secret.
                $changes += ['two_factor_secret' => $this->sandbox ? Str::upper(Str::random(32)) : self::TOTP_SECRET, 'two_factor_recovery_codes' => $hashed, 'two_factor_confirmed_at' => $this->now->subDays(30)];
            }
            $user->forceFill($changes)->save();
        }
        LegalAcceptance::query()->whereIn('user_id', $users->modelKeys())->update(['ip_address' => '198.51.100.24', 'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36']);
    }

    /** Rebooking tasks created by automations are worked like any other. */
    private function tidyAutomationTasks(): void
    {
        $demo = collect($this->orgs)->map->id->values()->all();
        foreach (Task::query()->whereIn('organization_id', $demo)->where('source', 'automation')->where('status', TaskStatus::Open->value)->where('created_at', '<', $this->now->subDays(2))->get() as $task) {
            $task->forceFill(['status' => TaskStatus::Completed, 'completed_at' => $task->created_at->addHours(mt_rand(3, 30)), 'completed_by_user_id' => $this->owner->id,
                'assigned_to_user_id' => $task->assigned_to_user_id ?? $this->staff->id])->saveQuietly();
        }
        Task::query()->whereIn('organization_id', $demo)->where('source', 'automation')->whereNull('assigned_to_user_id')->update(['assigned_to_user_id' => $this->staff->id]);
    }

    /** @return list<string> detail pages worth showing in a recording (ULIDs change on every rebuild) */
    private function notableUrls(): array
    {
        $r = $this->rivera;
        $o = $r->ulid;
        $call = CallLog::query()->where('organization_id', $r->id)->whereHas('escalations')->latest()->first();
        $customer = Customer::query()->where('organization_id', $r->id)->withCount('timeline')->orderByDesc('timeline_count')->first();
        $conversation = Conversation::query()->where('organization_id', $r->id)->where('contact_name', 'Tom Nguyen')->first();
        $aiChat = Conversation::query()->where('organization_id', $r->id)->where('contact_name', 'Kayla Brooks')->first();
        $review = SocialPost::query()->where('organization_id', $r->id)->where('status', 'in_review')->first();
        $invoice = Invoice::query()->where('organization_id', $r->id)->where('status', 'open')->first();
        $course = $this->courses['handling'];
        $certificate = DB::table('training_certificates')->where('agent_user_id', $this->john->id)->value('ulid');

        return [
            'Notable URLs:',
            "  client  /app/calls/{$call?->call_id}  (call with an escalation)",
            "  client  /app/customers/{$customer?->ulid}  ({$customer?->fullName()}, longest timeline)",
            "  client  /app/inbox/{$conversation?->ulid}  (Messenger, AI draft waiting)  ·  /app/inbox/{$aiChat?->ulid}  (website chat handled by AI)",
            "  client  /app/social/posts/{$review?->ulid}  (post awaiting approval)  ·  /app/billing/invoices/{$invoice?->ulid}  ({$invoice?->number}, part paid)",
            '  client  /app/seo?tab=work&task='.($this->seoTasks['gbp_posts'] ?? '').'  (Google posts awaiting review)',
            "  agent   /agent/businesses/{$o}  (Rivera call workspace)  ·  /agent/university/courses/{$course->ulid}  ·  /agent/university/certificates/{$certificate}",
            "  admin   /admin/organizations/{$o}  (Rivera)  ·  /admin/organizations/{$this->orgs['yoga']->ulid}  (paused)  ·  /admin/organizations/{$this->orgs['hvac']->ulid}  (onboarding)",
            "  admin   /agent/team/{$this->mike->id}  (Mike's training and quality)  ·  /agent/training/courses/{$course->ulid}  (course editor)",
        ];
    }

    private function report(float $started): void
    {
        $rivera = $this->rivera->id;
        $count = fn (string $class) => $class::query()->where('organization_id', $rivera)->count();
        $lines = [
            'Rivera: '.$count(CallLog::class).' calls, '.$count(Customer::class).' customers, '.$count(Appointment::class).' appointments ('
                .Appointment::query()->where('organization_id', $rivera)->where('starts_at', '>', $this->now)->count().' upcoming), '
                .$count(Task::class).' tasks, '.$count(Escalation::class).' escalations, '.$count(Conversation::class).' conversations, '
                .$count(SocialPost::class).' social posts, '.$count(Invoice::class).' invoices',
            'All businesses: '.CallLog::query()->count().' calls, '.Appointment::query()->count().' appointments, '.QaReview::query()->count().' QA reviews',
            ...$this->summary,
            'SEO tasks: '.implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($this->seoTasks), $this->seoTasks)),
            ...$this->notableUrls(),
            sprintf('Done in %.1fs. Sign in with password "password"; staff and agents use TOTP secret %s.', microtime(true) - $started, self::TOTP_SECRET),
        ];
        foreach ($lines as $line) {
            $this->command->info($line);
        }
    }
}
