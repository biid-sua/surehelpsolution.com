<?php

namespace App\Services\Sales;

use App\Mail\SalesChatReplyMail;
use App\Mail\SalesDemoBookedMail;
use App\Mail\SalesLeadAlertMail;
use App\Models\ContactSubmission;
use App\Models\SalesChat;
use App\Models\SalesChatMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Runs the website sales chat (D78). Buttons ("quick replies"), the contact form and the time picker
 * are handled here without the AI; free text goes to the AI when it's available, otherwise to a few
 * keyword answers. Every lead becomes a website enquiry the team sees under Admin › Website enquiries.
 */
class SalesChatDesk
{
    /** The response's extra UI for the widget: a time picker or the contact form. */
    private ?array $action = null;

    public function __construct(private SalesAssistant $assistant, private DemoSlots $slots) {}

    /**
     * @param  array{page?: mixed, referrer?: mixed, utm?: mixed, locale?: mixed}  $input
     * @return array{0: SalesChat, 1: string|null} the chat, and the visitor token when new
     */
    public function open(?string $token, array $input, ?string $ip): array
    {
        if ($chat = SalesChat::forToken($token)) {
            $chat->forceFill(['visitor_seen_at' => now(), 'page' => $this->clip($input['page'] ?? $chat->page, 255)])->save();

            return [$chat, null];
        }

        $token = Str::random(48);
        $utm = collect(is_array($input['utm'] ?? null) ? $input['utm'] : [])
            ->only(['source', 'medium', 'campaign', 'term', 'content'])
            ->map(fn ($v) => $this->clip($v, 100))->filter()->all();
        $chat = SalesChat::create([
            'token_hash' => hash('sha256', $token),
            'page' => $this->clip($input['page'] ?? '/', 255),
            'referrer' => $this->clip($input['referrer'] ?? null, 255),
            'utm' => $utm ?: null,
            'locale' => $this->clip($input['locale'] ?? null, 16),
            'ip_address' => $ip,
            'visitor_seen_at' => now(),
            'last_message_at' => now(),
        ]);
        $this->post($chat, 'assistant', $this->greeting($chat));

        return [$chat, $token];
    }

    public function chatsTodayFrom(?string $ip): int
    {
        return $ip ? SalesChat::query()->where('ip_address', $ip)->where('created_at', '>=', now()->subDay())->count() : 0;
    }

    /** A tapped quick reply: answered from the facts, no AI. @return list<SalesChatMessage> */
    public function quick(SalesChat $chat, string $id): array
    {
        $label = collect($this->quickReplies($chat))->firstWhere('id', $id)['label'] ?? null;
        abort_if($label === null, 422, 'Unknown option.');
        $new = [$this->post($chat, 'visitor', $label)];

        $reply = match ($id) {
            'pricing' => $this->pricingText(),
            'how' => 'Your calls are answered 24/7 by trained SureHelp receptionists, in your business\'s name, using your script, services and prices. '
                .'They book appointments into your Google or Outlook calendar, and every call shows up in your portal and app within seconds. '
                .'Setup takes about 48 hours.',
            'demo' => $this->offerTimes($chat),
            'trial' => 'You can start a free trial right now at **/start**, it takes about 5 minutes. Want me to book a quick demo first instead?',
            default => $this->handOver($chat, 'The visitor asked for a person.'),   // 'person'
        };
        $new[] = $this->post($chat, 'assistant', $reply);

        return $new;
    }

    /** Free text from the visitor. @return list<SalesChatMessage> */
    public function say(SalesChat $chat, string $text): array
    {
        $visitor = $this->post($chat, 'visitor', $text);

        // A person has the chat: they answer, not the AI.
        if ($chat->staff_user_id || $chat->needs_human) {
            $this->alertTeam($chat, 'New message in a chat waiting for the team', throttle: true);

            return [$visitor];
        }

        $answer = $this->assistant->available($chat) ? $this->assistant->answer($chat, fn (string $tool, array $input) => $this->runTool($chat, $tool, $input)) : null;
        $byAi = $answer !== null;
        $answer ??= $this->fallback($chat, $text);

        return [$visitor, $this->post($chat->fresh(), 'assistant', $answer, $byAi)];
    }

    /**
     * The contact form. @param array<string, mixed> $input
     *
     * @return list<SalesChatMessage>
     */
    public function saveContact(SalesChat $chat, array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{7,30}$/'],
            'company' => ['nullable', 'string', 'max:120'],
            'sms_consent' => ['nullable', 'boolean'],
        ], ['phone.regex' => 'Enter a valid phone number.'])->validate();

        $chat->remember(array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_intersect_key($data, array_flip(['name', 'email', 'phone', 'company']))));
        $chat->save();
        $enquiry = $this->syncEnquiry($chat, smsConsent: (bool) ($data['sms_consent'] ?? false));

        $first = Str::before(trim($data['name']).' ', ' ');
        if ($chat->pending_slot && ($booked = $this->bookSlot($chat, $chat->pending_slot))) {
            return [$this->post($chat, 'assistant', "Thanks, {$first}! ".$booked)];
        }
        if ($chat->needs_human) {
            return [$this->post($chat, 'assistant', "Thanks, {$first}! The team has your details and will reply here and by email at {$enquiry->email}.")];
        }
        if ($chat->wants_demo && $enquiry->demo_status !== 'booked') {
            return [$this->post($chat, 'assistant', "Thanks, {$first}! ".$this->offerTimes($chat))];
        }

        return [$this->post($chat, 'assistant', "Thanks, {$first}! What else can I help you with?")];
    }

    /** A tapped time. @return list<SalesChatMessage> */
    public function book(SalesChat $chat, string $startsAt): array
    {
        if (! $this->slots->check($startsAt)) {
            throw new HttpResponseException(response()->json(['error' => 'Sorry, that time was just taken. Here are the times still open.', 'action' => $this->slotsAction()], 409));
        }
        $chat->forceFill(['wants_demo' => true, 'pending_slot' => $startsAt])->save();

        if (! $chat->hasContact()) {
            $this->action = $this->contactAction('Your name and email, so we can send the invitation');

            return [$this->post($chat, 'assistant', 'Great choice! Just your name and email and you\'re booked.')];
        }

        $booked = $this->bookSlot($chat, $startsAt);

        return [$this->post($chat, 'assistant', $booked ?? 'Sorry, that time was just taken. Please pick another one.')];
    }

    /** A person on the SureHelp team replies (Admin › Website enquiries). */
    public function teamReply(SalesChat $chat, User $user, string $text): SalesChatMessage
    {
        $chat->forceFill(['staff_user_id' => $user->id, 'needs_human' => true])->save();
        $message = $this->post($chat, 'team', $text, false, $user);
        $email = $chat->lead['email'] ?? null;
        // The visitor has left the page: send the reply by email too.
        if ($email && (! $chat->visitor_seen_at || $chat->visitor_seen_at->lt(now()->subMinutes(2)))) {
            Mail::to($email)->queue(new SalesChatReplyMail($chat, $message));
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function payload(SalesChat $chat, array $messages, ?string $token = null): array
    {
        $chat->refresh();
        $enquiry = $chat->enquiry;

        return array_filter([
            'token' => $token,
            'messages' => array_map(fn (SalesChatMessage $m) => $m->toWidget(), $messages),
            'quick_replies' => $this->quickReplies($chat),
            'lead' => ['name' => $chat->lead['name'] ?? null, 'email' => $chat->lead['email'] ?? null, 'phone' => $chat->lead['phone'] ?? null,
                'company' => $chat->lead['company'] ?? null, 'complete' => $chat->hasContact()],
            'booking' => $enquiry?->demo_status === 'booked' && $enquiry->demo_at
                ? ['starts_at' => $enquiry->demo_at->toIso8601String(), 'label' => $this->slots->label(CarbonImmutable::parse($enquiry->demo_at)), 'timezone' => $this->slots->timezone()]
                : null,
            'staff_joined' => (bool) $chat->staff_user_id,
            'action' => $this->action,
        ], fn ($v, $k) => $v !== null || in_array($k, ['booking', 'action'], true), ARRAY_FILTER_USE_BOTH);
    }

    /** @return list<array{id: string, label: string}> */
    public function quickReplies(SalesChat $chat): array
    {
        if ($chat->staff_user_id) {
            return [];
        }
        $booked = $chat->enquiry?->demo_status === 'booked';

        return array_values(array_filter([
            $booked ? null : ['id' => 'demo', 'label' => 'Book a demo'],
            ['id' => 'pricing', 'label' => 'Pricing'],
            ['id' => 'how', 'label' => 'How it works'],
            ['id' => 'trial', 'label' => 'Start a free trial'],
            $chat->needs_human ? null : ['id' => 'person', 'label' => 'Talk to a person'],
        ]));
    }

    /**
     * The AI's tools, run with the same code as the buttons and forms.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, content: string}
     */
    private function runTool(SalesChat $chat, string $tool, array $input): array
    {
        switch ($tool) {
            case 'save_lead_details':
                $fields = collect($input)->only(['name', 'email', 'phone', 'company', 'industry', 'team_size', 'monthly_calls', 'current_solution', 'needs', 'timeline', 'plan'])
                    ->map(fn ($v) => $this->clip($v, 200))->filter();
                if ($fields->has('email') && ! filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
                    $fields->forget('email');
                }
                $chat->remember($fields->all());
                $chat->save();
                $this->syncEnquiry($chat);

                return ['ok' => true, 'content' => 'Saved.'];

            case 'offer_demo_times':
                $chat->forceFill(['wants_demo' => true])->save();
                $this->action = $this->slotsAction($input['from_date'] ?? null, ($input['part_of_day'] ?? 'any') === 'any' ? null : $input['part_of_day']);
                $times = collect($this->action['days'])->map(fn ($d) => $d['label'].' ('.$d['date'].'): '.collect($d['slots'])->map(fn ($s) => $s['label'].' = '.$s['starts_at'])->implode(', '))->implode("\n");

                return ['ok' => true, 'content' => $times !== '' ? "Shown to the visitor as buttons:\n".$times : 'No open times in the next two weeks; offer hand_over_to_team.'];

            case 'book_demo':
                $startsAt = (string) ($input['starts_at'] ?? '');
                if (! $this->slots->check($startsAt)) {
                    $this->action = $this->slotsAction();

                    return ['ok' => false, 'content' => 'That time is not open. Fresh times are shown to the visitor; ask them to pick one.'];
                }
                $chat->forceFill(['wants_demo' => true, 'pending_slot' => $startsAt])->save();
                if (! $chat->hasContact()) {
                    $this->action = $this->contactAction('Your name and email, so we can send the invitation');

                    return ['ok' => true, 'content' => 'The contact form is shown; the demo is booked as soon as they send it. Tell them that.'];
                }
                $booked = $this->bookSlot($chat, $startsAt);

                return $booked ? ['ok' => true, 'content' => 'Booked. Tell them: '.$booked] : ['ok' => false, 'content' => 'That time was just taken; offer other times.'];

            case 'show_contact_form':
                $this->action = $this->contactAction($this->clip($input['reason'] ?? null, 120) ?: 'So our team can get back to you');

                return ['ok' => true, 'content' => 'The form is shown.'];

            case 'hand_over_to_team':
                $this->handOver($chat, $this->clip($input['reason'] ?? 'Asked for a person', 300));

                return ['ok' => true, 'content' => $chat->hasContact()
                    ? 'The team has been told and will reply here and by email.'
                    : 'The team has been told; the contact form is shown so they can reply by email too.'];
        }

        return ['ok' => false, 'content' => 'Unknown tool.'];
    }

    private function bookSlot(SalesChat $chat, string $startsAt): ?string
    {
        $at = $this->slots->check($startsAt);
        $enquiry = $this->syncEnquiry($chat);
        if (! $at || ! $enquiry || ! $this->slots->book($enquiry, $at)) {
            $this->action = $this->slotsAction();

            return null;
        }
        $chat->forceFill(['pending_slot' => null])->save();
        $enquiry->forceFill(['score' => 'hot'])->save();
        Mail::to($enquiry->email)->queue(new SalesDemoBookedMail($enquiry->fresh()));
        $this->alertTeam($chat, 'Demo booked: '.$this->slots->label($at));
        $this->action = null;

        return "You're booked for **{$this->slots->label($at)}**. We've emailed the invitation to {$enquiry->email}. Anything you'd like us to prepare?";
    }

    private function handOver(SalesChat $chat, string $reason): string
    {
        $chat->forceFill(['needs_human' => true])->save();
        $this->syncEnquiry($chat);
        $this->alertTeam($chat, 'Chat handed to the team: '.$reason);
        if (! $chat->hasContact()) {
            $this->action = $this->contactAction('So the team can reply by email if you leave');

            return 'Sure, I\'ve let the team know. They usually reply within a few minutes during business hours. Leave your name and email in case you step away.';
        }

        return 'Sure, I\'ve let the team know. They\'ll reply right here, and by email at '.$chat->lead['email'].' if you step away.';
    }

    /** Create or update the website enquiry once we know who they are. */
    private function syncEnquiry(SalesChat $chat, ?bool $smsConsent = null): ?ContactSubmission
    {
        if (! $chat->hasContact()) {
            return null;
        }
        $lead = $chat->lead;
        $details = array_diff_key($lead, array_flip(['name', 'email', 'phone', 'company']));
        $enquiry = $chat->enquiry ?? new ContactSubmission(['source' => 'chat', 'ip_address' => $chat->ip_address]);
        $isNew = ! $enquiry->exists;
        $enquiry->fill([
            'name' => $lead['name'],
            'email' => $lead['email'],
            'phone' => $lead['phone'] ?? $enquiry->phone,
            'company' => $lead['company'] ?? $enquiry->company,
            'inquiry_type' => $chat->wants_demo || $enquiry->demo_status === 'booked' ? 'demo' : ($chat->needs_human ? 'sales' : ($enquiry->inquiry_type ?? 'sales')),
            'message' => $this->summary($chat),
            'details' => $details ?: null,
            'score' => LeadScore::for($lead, $enquiry->demo_status === 'booked'),
        ]);
        if ($smsConsent !== null) {
            $enquiry->sms_consent = $smsConsent;
        }
        $enquiry->save();
        if ($isNew) {
            $chat->forceFill(['contact_submission_id' => $enquiry->id])->save();
            $chat->setRelation('enquiry', $enquiry);
            $this->alertTeam($chat, 'New lead from the website chat');
        }

        return $enquiry;
    }

    private function summary(SalesChat $chat): string
    {
        $lead = $chat->lead ?? [];
        $parts = array_filter([
            filled($lead['needs'] ?? null) ? 'Needs: '.$lead['needs'] : null,
            filled($lead['industry'] ?? null) ? 'Industry: '.$lead['industry'] : null,
            filled($lead['monthly_calls'] ?? null) ? 'Calls a month: '.$lead['monthly_calls'] : null,
            filled($lead['timeline'] ?? null) ? 'Start: '.$lead['timeline'] : null,
        ]);

        return ($parts ? implode("\n", $parts)."\n\n" : '').'Lead from the website chat'.($chat->page ? ' (page '.$chat->page.')' : '').'.';
    }

    private function alertTeam(SalesChat $chat, string $subject, bool $throttle = false): void
    {
        $to = config('sales.notify') ?: config('mail.contact_to', config('company.email'));
        if (! $to || ($throttle && ! cache()->add('sales-chat-alert:'.$chat->id, 1, now()->addMinutes(10)))) {
            return;
        }
        Mail::to($to)->queue(new SalesLeadAlertMail($chat, $subject));
    }

    private function offerTimes(SalesChat $chat): string
    {
        $chat->forceFill(['wants_demo' => true])->save();
        $this->action = $this->slotsAction();

        return $this->action['days'] === []
            ? 'Our calendar is full for the next two weeks, sorry. Leave your details and the team will find a time with you.'
            : 'Pick a time that suits you for a 30-minute demo with our team (times are '.$this->action['timezone_label'].'):';
    }

    /** @return array<string, mixed> */
    private function slotsAction(?string $from = null, ?string $part = null): array
    {
        $tz = $this->slots->timezone();

        return [
            'type' => 'slots',
            'timezone' => $tz,
            'timezone_label' => CarbonImmutable::now($tz)->format('T'),
            'days' => $this->slots->days($from, 5, 8, $part),
        ];
    }

    /** @return array<string, mixed> */
    private function contactAction(string $reason): array
    {
        return ['type' => 'contact', 'reason' => $reason, 'fields' => ['name', 'email', 'phone', 'company'], 'required' => ['name', 'email']];
    }

    private function fallback(SalesChat $chat, string $text): string
    {
        $t = Str::lower($text);

        return match (true) {
            (bool) preg_match('/price|pricing|cost|how much|plan/', $t) => $this->pricingText(),
            (bool) preg_match('/demo|book|call me|meeting|talk/', $t) => $this->offerTimes($chat),
            (bool) preg_match('/human|person|agent|someone|team/', $t) => $this->handOver($chat, 'Asked for a person: '.$this->clip($text, 200)),
            default => $chat->hasContact()
                ? 'Thanks! I\'ve passed that to the team and they\'ll get back to you shortly. Meanwhile I can show pricing or book a demo.'
                : tap('Good question. I can show pricing, explain how it works, or book a quick demo with the team. Or leave your details and they\'ll get back to you.', function () {
                    $this->action = $this->contactAction('So our team can get back to you');
                }),
        };
    }

    private function pricingText(): string
    {
        $plans = collect(config('sales.plans', []))->map(fn (array $p) => '**'.$p['name'].'**: '
            .($p['monthly'] ? '$'.$p['monthly'].'/month, '.$p['minutes'] : 'custom pricing for '.$p['best_for']))->implode("\n");

        return "Our plans:\n{$plans}\nAnnual billing saves 10%, there are no setup fees and every plan starts with a free trial. Want help picking one?";
    }

    private function greeting(SalesChat $chat): string
    {
        $name = config('sales.assistant', 'Ava');

        return str_contains((string) $chat->page, 'pricing')
            ? "Hi! I'm {$name}, SureHelp's assistant. Questions about plans? I can help you pick one or book a quick demo with the team."
            : "Hi! I'm {$name}, SureHelp's assistant. I can answer questions about our 24/7 answering service, show pricing, or book a quick demo with the team. What brings you here today?";
    }

    private function post(SalesChat $chat, string $author, string $body, bool $byAi = false, ?User $user = null): SalesChatMessage
    {
        $chat->forceFill(['last_message_at' => now()])->save();

        return $chat->messages()->create(['author' => $author, 'body' => $body, 'by_ai' => $byAi, 'user_id' => $user?->id]);
    }

    private function clip(mixed $value, int $length): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim(strip_tags($value)), $length, '') : null;
    }
}
