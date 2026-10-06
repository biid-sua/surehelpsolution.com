<?php

namespace App\Console\Commands;

use App\Enums\LessonType;
use App\Models\TrainingCategory;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TrainingModule;
use App\Models\TrainingQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Two platform-wide starter courses for Agent University, created as drafts for an administrator
 * to review, adapt and publish (nothing reaches agents until then). Safe to run again: existing
 * courses with the same title are left alone.
 */
class InstallTrainingStarter extends Command
{
    protected $signature = 'training:starter';

    protected $description = 'Create draft starter courses for Agent University (call handling; privacy and security)';

    public function handle(): int
    {
        foreach ($this->courses() as $course) {
            if (TrainingCourse::query()->whereNull('organization_id')->where('title', $course['title'])->exists()) {
                $this->line("Skipped \"{$course['title']}\": it already exists.");

                continue;
            }
            DB::transaction(fn () => $this->create($course));
            $this->info("Created draft \"{$course['title']}\".");
        }
        $this->line('Review and publish them in the agent portal: Training › Courses.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function create(array $data): void
    {
        $category = TrainingCategory::query()->firstOrCreate(['name' => $data['category']]);
        $course = TrainingCourse::create([
            'title' => $data['title'], 'summary' => $data['summary'], 'description' => $data['description'], 'category_id' => $category->id,
            'difficulty' => 'beginner', 'estimated_minutes' => $data['minutes'], 'valid_for_months' => 12,
            'issues_certificate' => true, 'certificate_name' => $data['certificate'],
        ]);

        foreach ($data['modules'] as $m => $module) {
            $row = TrainingModule::create(['course_id' => $course->id, 'title' => $module['title'], 'position' => $m]);
            foreach ($module['lessons'] as $l => $lesson) {
                $quiz = isset($lesson['questions']);
                $model = TrainingLesson::create([
                    'course_id' => $course->id, 'module_id' => $row->id, 'title' => $lesson['title'], 'position' => $l,
                    'type' => $quiz ? LessonType::Quiz : LessonType::Text, 'body' => $lesson['body'] ?? null,
                    'duration_minutes' => $lesson['minutes'] ?? null,
                    'pass_percent' => $quiz ? 80 : null, 'max_attempts' => $quiz ? 3 : null, 'shuffle_questions' => $quiz,
                ]);
                foreach ($lesson['questions'] ?? [] as $q => [$type, $prompt, $options, $explanation, $scenario]) {
                    TrainingQuestion::create([
                        'lesson_id' => $model->id, 'type' => $type, 'scenario' => $scenario, 'prompt' => $prompt, 'explanation' => $explanation, 'position' => $q,
                        'options' => array_map(fn (array $o) => ['id' => TrainingQuestion::optionId(), 'label' => $o[0], 'correct' => $o[1]], $options),
                    ]);
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function courses(): array
    {
        return [
            [
                'title' => 'SureHelp Call Handling Standards',
                'category' => 'Customer service',
                'minutes' => 25,
                'certificate' => 'SureHelp Certified Receptionist',
                'summary' => 'How every SureHelp agent opens, handles, books and closes a call, for any business.',
                'description' => "Callers judge a business in the first few seconds. This course sets the standard every SureHelp agent follows on every call, whichever company they answer for.\n\nCompany-specific details (services, prices, hours, escalation contacts) come from each company's own training and the briefing in the workspace.",
                'modules' => [
                    ['title' => 'Opening the call', 'lessons' => [[
                        'title' => 'The first ten seconds', 'minutes' => 5,
                        'body' => "## Answer promptly\nPick up within three rings. A caller who waits starts the call annoyed.\n\n## Use the greeting\nCheck **Currently viewing** before you speak, then say:\n\n> \"Thank you for calling *[business name]*, this is *[your first name]*. How can I help you today?\"\n\n- Say the business name exactly as the company wrote it.\n- Smile while you talk: it changes your tone.\n- Never answer with just \"Hello?\".\n\n## If you're unsure which business\nLook at the workspace before speaking. Greeting a caller with the wrong business name is the most damaging mistake an agent can make.",
                    ]]],
                    ['title' => 'Understanding the caller', 'lessons' => [[
                        'title' => 'Listen, confirm, capture', 'minutes' => 7,
                        'body' => "## Listen first\nLet the caller finish. Take notes as they talk, then summarise: \"So the kitchen sink is leaking and you'd like someone today, is that right?\"\n\n## Confirm the details\n- **Name:** ask them to spell it if there's any doubt.\n- **Phone number:** read it back digit by digit.\n- **Address:** confirm the street and the town or ZIP code.\n- **Email:** spell it back, especially the part before the @.\n\n## Stay within what the business allows\nOnly quote prices, times and policies that are in the company's information. If you don't know: \"Let me make sure you get the right answer: I'll have the team call you back.\" Never guess.",
                    ]]],
                    ['title' => 'Booking and escalating', 'lessons' => [[
                        'title' => 'Bookings, follow-ups and urgent calls', 'minutes' => 8,
                        'body' => "## Booking\nUse the booking panel in the workspace. It only offers times the business allows (hours, holidays, service rules), so don't promise a time before you've checked it.\n\n## Follow-ups\nIf the caller needs a callback, choose the callback outcome. The business gets a task with a due time; say when they can expect the call: \"Someone from the team will call you back this afternoon.\"\n\n## Urgent calls\nFollow the company's escalation rules shown in the briefing. If there's a risk to safety (gas smell, flooding, someone hurt), tell the caller to contact emergency services first, then escalate straight away.\n\n## Closing\nRecap what happens next, thank them by name, and let them hang up first.",
                    ]]],
                    ['title' => 'Check your understanding', 'lessons' => [[
                        'title' => 'Call handling quiz',
                        'body' => 'Five questions. You need 80% to pass.',
                        'questions' => [
                            ['single', 'What should you check before you greet the caller?', [['Which business is shown under "Currently viewing"', true], ['The weather in the caller\'s area', false], ['How many calls are waiting', false]], 'Greeting with the wrong business name is the most damaging mistake.', null],
                            ['multiple', 'Which details do you read back to the caller?', [['Phone number', true], ['Name spelling when unsure', true], ['Your own shift times', false], ['Email address', true]], 'Reading back catches mistakes while the caller is still on the line.', null],
                            ['true_false', 'You may quote a price the caller says they were given last year.', [['True', false], ['False', true]], 'Only quote prices that are in the company\'s current information.', null],
                            ['scenario', 'What do you do first?', [['Tell them to leave the building and call the gas company\'s emergency line, then escalate', true], ['Book the next available appointment', false], ['Take a message for tomorrow', false]], 'Safety comes first; then follow the company\'s escalation rules.', 'A caller says they can smell gas in their kitchen and asks when a plumber can come.'],
                            ['single', 'How should you end the call?', [['Recap what happens next, thank them by name, and let them hang up first', true], ['Say "bye" and hang up quickly to take the next call', false], ['Ask them to rate you', false]], 'A clear recap prevents repeat calls and complaints.', null],
                        ],
                    ]]],
                ],
            ],
            [
                'title' => 'Privacy and Security Essentials',
                'category' => 'Security and compliance',
                'minutes' => 15,
                'certificate' => 'Privacy & Security Aware',
                'summary' => 'Protecting callers\' information and each company\'s data. Renewed every year.',
                'description' => 'Every company trusts us with its customers. This course covers what agents must always do, and never do, with personal and company information. It must be renewed every 12 months.',
                'modules' => [
                    ['title' => 'Protecting information', 'lessons' => [
                        [
                            'title' => 'The rules', 'minutes' => 8,
                            'body' => "## Collect only what's needed\nAsk for the details the call needs, nothing more.\n\n## Never take card details by phone\nDon't write down or repeat card numbers, security codes or bank passwords. If a caller wants to pay, use the company's payment link or ask the business to call back.\n\n## Keep companies separate\nYou may serve several companies. Never mention one company's customers, prices or problems to another. The portal only shows you the companies you're assigned to; keep it that way in conversation too.\n\n## Check who you're talking to\nBefore discussing an existing appointment or account, confirm the caller's name and phone number match the record.\n\n## Protect your screen and account\n- Lock your screen when you step away.\n- Never share your password or two-step sign-in codes.\n- Work only on approved devices and networks.\n\n## Report problems\nIf something looks wrong (a suspicious call, a message sent to the wrong person, a lost device), tell your supervisor straight away. Reporting quickly limits the harm.",
                        ],
                        [
                            'title' => 'Privacy quiz',
                            'questions' => [
                                ['true_false', 'It\'s fine to write a caller\'s card number in the notes if they ask you to.', [['True', false], ['False', true]], 'Card details are never written down or repeated; use the payment link.', null],
                                ['scenario', 'What do you say?', [['That you can only help with this company\'s customers', true], ['Share the neighbour\'s appointment time to be helpful', false], ['Look it up in another company\'s calendar', false]], 'Each company\'s information stays with that company.', 'A caller for ABC Plumbing asks whether their neighbour, a customer of a cleaning company you also serve, has booked this week.'],
                                ['multiple', 'Which of these should you report to your supervisor?', [['A message sent to the wrong customer', true], ['A caller pressuring you for another person\'s details', true], ['A lost work phone', true], ['A caller who thanked you', false]], 'Report anything that could expose someone\'s information, quickly.', null],
                                ['single', 'Before discussing an existing appointment you should:', [['Confirm the caller\'s name and phone number match the record', true], ['Read out the full address on file', false], ['Ask for their password', false]], 'Confirm identity without revealing the details you hold.', null],
                            ],
                        ],
                    ]],
                ],
            ],
        ];
    }
}
