<?php

namespace App\Services\Telephony;

use App\Enums\InboxChannel;
use App\Exceptions\AiUnavailable;
use App\Models\AiAssistant;
use App\Models\AiRun;
use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\VoiceCall;
use App\Models\VoiceReceptionist;
use App\Services\Ai\Assistant\AssistantTools;
use App\Services\Ai\Assistant\BusinessBrain;
use App\Services\Ai\Assistant\ToolOutcome;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\AiRequest;
use App\Services\Billing\FeatureAccess;
use Illuminate\Support\Str;

/**
 * The AI receptionist (D108): answers a business's calls when its people can't, by voice. Twilio
 * turns the caller's speech into text and reads the replies aloud; in between, the same brain and
 * tools as the messaging assistant (D38) answer questions, book, take details and hand over.
 *
 * Each call it answers is a conversation on the Phone channel, so the owner reads the whole call in
 * the Inbox. One webhook per turn: no long-running connection, so it runs on any PHP host.
 */
class AiReceptionist
{
    /** Turns before it wraps up politely; a guard against a call going round in circles. */
    public const MAX_TURNS = 24;

    /** Two silences in a row: the caller can't be heard, so the team calls back. */
    public const MAX_SILENCES = 2;

    public function __construct(
        private readonly AiProvider $ai,
        private readonly BusinessBrain $brain,
        private readonly AssistantTools $tools,
        private readonly FeatureAccess $features,
    ) {}

    /** When it answers this business's calls now ("backup", "after_hours", "always"), or null when it can't. */
    public function modeFor(Organization $organization): ?string
    {
        if (! $this->features->allows($organization, 'ai_receptionist') || ! $this->ai->isConfigured()) {
            return null;
        }
        $mode = VoiceReceptionist::for($organization)->mode;

        return $mode !== 'off' && array_key_exists($mode, VoiceReceptionist::MODES) ? $mode : null;
    }

    /** It picks up: the call becomes a conversation, it greets the caller and listens. */
    public function start(VoiceCall $call, TwiML $twiml): string
    {
        $organization = Organization::query()->findOrFail($call->organization_id);
        $settings = VoiceReceptionist::for($organization);
        $conversation = Conversation::create([
            'organization_id' => $organization->id, 'channel' => InboxChannel::Phone, 'channel_key' => (string) $call->to_number,
            'external_thread_id' => $call->ulid, 'customer_id' => $call->customer_id,
            'contact_name' => $call->customer?->fullName(), 'contact_handle' => $call->fromDisplay(),
        ]);
        $call->forceFill([
            'ai_conversation_id' => $conversation->id, 'status' => VoiceCall::IN_PROGRESS, 'answered_at' => now(),
            'wait_seconds' => max(0, (int) $call->started_at->diffInSeconds(now())),
        ])->save();

        $greeting = $settings->greetingFor($this->businessName($organization));
        $this->said($conversation, $greeting);

        return $this->listen($twiml, $call, $settings, $greeting)->xml();
    }

    /**
     * The caller said something (or nothing): reply and listen again, or end, or pass to the owner.
     *
     * @param  array<string, mixed>  $params  Twilio's Gather result
     */
    public function turn(VoiceCall $call, array $params): string
    {
        $conversation = $call->ai_conversation_id ? Conversation::withoutGlobalScopes()->find($call->ai_conversation_id) : null;
        if (! $conversation || $call->ended_at !== null) {
            return TwiML::make()->hangup()->xml();
        }
        $organization = $conversation->organization;
        $settings = VoiceReceptionist::for($organization);
        $speech = trim((string) ($params['SpeechResult'] ?? ''));

        if ($speech === '') {
            $call->forceFill(['ai_silences' => $call->ai_silences + 1])->save();
            if ($call->ai_silences >= self::MAX_SILENCES) {
                return $this->wrapUp($call, $conversation, $settings, 'I\'m sorry, I can\'t hear you. Someone from the team will call you back. Goodbye.',
                    'The caller couldn\'t be heard by the AI receptionist. Call them back.');
            }

            return $this->listen(TwiML::make(), $call, $settings, 'Sorry, I didn\'t catch that. How can I help?')->xml();
        }

        $call->forceFill(['ai_silences' => 0, 'ai_turns' => $call->ai_turns + 1])->save();
        $this->heard($conversation, $speech);
        if ($call->ai_turns > self::MAX_TURNS || $call->answered_at?->lt(now()->subMinutes(max(2, $settings->max_minutes)))) {
            return $this->wrapUp($call, $conversation, $settings, 'I\'ve noted everything, and someone from the team will call you back to finish this. Thanks for calling. Goodbye.',
                'The call with the AI receptionist ran long. Read the conversation and call them back.');
        }

        $line = $call->phoneNumber;
        $canTransfer = $settings->transfer_to_owner && filled($line?->transfer_number);
        $reply = $this->respond($organization, $conversation, $settings, $this->history($conversation), (string) $call->from_number, $canTransfer, practice: false);
        $this->said($conversation, $reply['text'], $reply['run']);

        if ($reply['transfer'] && $line) {
            return app(CallRouter::class)->transferFromAi($call, $line, TwiML::make()->say($reply['text'], $settings->voiceName()));
        }
        if ($reply['end']) {
            return TwiML::make()->say($reply['text'], $settings->voiceName())->hangup()->xml();
        }

        return $this->listen(TwiML::make(), $call, $settings, $reply['text'])->xml();
    }

    /**
     * One reply: the model may look things up and act (book, save details, follow up, hand over)
     * before it speaks. In practice (the owner trying it from the settings page) nothing is really
     * booked, saved or handed over.
     *
     * @param  list<array{role: string, content: string|array<int, mixed>}>  $history  the call so far, starting with the caller
     * @return array{text: string, end: bool, transfer: bool, handed_over: bool, run: ?AiRun, actions: list<string>}
     */
    public function respond(Organization $organization, Conversation $conversation, VoiceReceptionist $settings, array $history, string $callerNumber, bool $canTransfer, bool $practice): array
    {
        $assistant = AiAssistant::for($organization);
        $assistant->can_book = $settings->can_book;   // the receptionist's own switch, not the messaging assistant's
        $business = $this->businessName($organization);

        $instructions = $this->brain->instructions($organization, $assistant, true)."\n\n".$this->voiceRules($business, $callerNumber, $canTransfer, $practice);
        $context = $this->brain->context($conversation, true);
        $definitions = [...$this->tools->definitions($assistant, true), ...$this->voiceTools($canTransfer)];

        $outcome = new ToolOutcome;
        $end = false;
        $transfer = false;
        $calls = [];
        $actions = [];
        $usage = ['input' => 0, 'output' => 0, 'cache_read' => 0];
        $steps = 0;
        $text = '';
        $messages = $history;

        try {
            while (true) {
                $steps++;
                $response = $this->ai->complete(new AiRequest($instructions, $context, $messages, $definitions,
                    effort: (string) config('ai.receptionist.effort', 'low'), model: config('ai.receptionist.model') ?: null, maxTokens: 800));
                foreach ($usage as $key => $value) {
                    $usage[$key] = $value + $response->usage[$key];
                }
                $text = $response->text;
                if (! $response->wantsTools() || $steps >= 5) {
                    break;
                }

                $messages[] = ['role' => 'assistant', 'content' => $response->providerContent];
                $results = [];
                foreach ($response->toolCalls as $tool) {
                    $input = (array) $tool['input'];
                    $result = match (true) {
                        $tool['name'] === 'end_call' => ['ok' => true, 'content' => 'The call will end after your goodbye.', 'summary' => 'ending the call'],
                        $tool['name'] === 'transfer_to_owner' => $canTransfer
                            ? ['ok' => true, 'content' => 'You will be transferred. Tell the caller you\'re putting them through now.', 'summary' => 'transferring to the owner']
                            : ['ok' => false, 'content' => 'Transfers aren\'t available. Take their details and create a follow-up instead.', 'summary' => 'no transfer available'],
                        $practice && in_array($tool['name'], AssistantTools::ACTIONS, true) => [
                            'ok' => true, 'content' => 'Practice call: nothing was really done. Carry on as if it worked.', 'summary' => 'practice: '.$tool['name'],
                        ],
                        default => $this->tools->run($tool['name'], $input, $conversation, $assistant, $outcome),
                    };
                    if ($tool['name'] === 'end_call') {
                        $end = true;
                    }
                    if ($tool['name'] === 'transfer_to_owner' && $canTransfer) {
                        $transfer = true;
                    }
                    if ($result['ok'] && in_array($tool['name'], [...AssistantTools::ACTIONS, 'transfer_to_owner'], true)) {
                        $actions[] = $tool['name'];
                    }
                    $calls[] = ['name' => $tool['name'], 'input' => $input, 'ok' => $result['ok'], 'summary' => $result['summary']];
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $tool['id'], 'content' => $result['content'], 'is_error' => ! $result['ok']];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AiUnavailable $e) {
            report($e);
            if (! $practice) {
                $this->tools->run('create_follow_up', ['summary' => 'The AI receptionist had a problem during this call. Read the conversation and call them back.', 'call_back' => true], $conversation, $assistant, $outcome);
            }

            return ['text' => 'I\'m sorry, I\'m having trouble right now. I\'ve asked the team to call you back. Goodbye.', 'end' => true, 'transfer' => false,
                'handed_over' => false, 'run' => $practice ? null : $this->log($conversation, $calls, $usage, $steps, 'failed', 'The AI service is unavailable.'), 'actions' => $actions];
        }

        $text = $this->speakable($text);
        if ($outcome->handedOver && ! $transfer) {
            $end = true;
            $text = $text !== '' ? $text : 'I\'ve passed this to the team, and someone will call you back as soon as possible. Goodbye.';
        }
        if ($text === '') {
            $text = $end ? 'Thanks for calling. Goodbye.' : 'Is there anything else I can help with?';
        }

        return [
            'text' => $text, 'end' => $end, 'transfer' => $transfer, 'handed_over' => $outcome->handedOver, 'actions' => $actions,
            'run' => $practice ? null : $this->log($conversation, $calls, $usage, $steps, $outcome->handedOver ? 'handed_over' : 'replied', null),
        ];
    }

    /**
     * The call is over. One where the caller never spoke counts as missed, so the team calls back.
     */
    public function ended(VoiceCall $call): void
    {
        $conversation = Conversation::withoutGlobalScopes()->find($call->ai_conversation_id);
        if (! $conversation) {
            return;
        }
        $spoke = $conversation->messages()->where('direction', Message::IN)->exists();
        if (! $spoke && in_array($call->status, [VoiceCall::COMPLETED, VoiceCall::IN_PROGRESS], true)) {
            $call->forceFill(['status' => VoiceCall::MISSED])->save();
        }
        $conversation->forceFill(['unread_count' => max(1, $conversation->unread_count)])->save();
    }

    /** @return list<array{name: string, description: string, input_schema: array<string, mixed>}> */
    private function voiceTools(bool $canTransfer): array
    {
        $tools = [[
            'name' => 'end_call',
            'description' => 'End the call after your goodbye, once the caller has what they need and nothing else. Your reply is spoken first.',
            'input_schema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string', 'description' => 'One sentence on how the call went']], 'additionalProperties' => false],
        ]];
        if ($canTransfer) {
            $tools[] = [
                'name' => 'transfer_to_owner',
                'description' => 'Put the caller through to the business owner\'s phone: when they ask for a person, for an emergency, or for anything you can\'t handle. Say you\'re putting them through.',
                'input_schema' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']], 'required' => ['reason'], 'additionalProperties' => false],
            ];
        }

        return $tools;
    }

    private function voiceRules(string $business, string $callerNumber, bool $canTransfer, bool $practice): string
    {
        $number = $callerNumber !== '' ? PhoneNumber::pretty($callerNumber) : null;
        $escalate = $canTransfer
            ? 'For an emergency, when they ask for a person, or anything you can\'t handle: use transfer_to_owner.'
            : 'For an emergency, when they ask for a person, or anything you can\'t handle: take their name and number, then use hand_over_to_team (the team calls them back).';

        return "# This is a phone call\n"
            ."You are answering the phone for {$business}, not writing messages. Everything you write is read aloud by a voice, so:\n"
            ."- Speak naturally in one to three short sentences. Ask one question at a time.\n"
            ."- No lists, symbols, emojis or links. Say times and dates the way people say them (\"Tuesday at two thirty\").\n"
            ."- You greeted the caller as the virtual assistant. If they ask, say honestly that you're an AI assistant.\n"
            .($number ? "- The caller is calling from {$number}. Confirm it's the best number rather than asking them to read it out, and use it when booking or saving details.\n" : '')
            ."- Get their name early. Before booking, repeat the service, day and time back and get a yes.\n"
            ."- {$escalate}\n"
            .'- When they have what they need and nothing else, say a short goodbye and use end_call.'
            .($practice ? "\n\nThis is a practice call: the business owner is testing you by typing. Answer exactly as you would on a real call." : '');
    }

    /**
     * The conversation so far, as the model sees it: the caller is "user", the receptionist "assistant".
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(Conversation $conversation): array
    {
        $out = [];
        foreach ($conversation->messages()->reorder()->where('is_note', false)->latest('id')->limit(40)->get()->reverse() as $m) {
            $role = $m->direction === Message::IN ? 'user' : 'assistant';
            if ($out === [] && $role === 'assistant') {
                continue; // it starts with the caller; the greeting is in the instructions' spirit already
            }
            $out[] = ['role' => $role, 'content' => (string) $m->body];
        }

        return $out;
    }

    private function listen(TwiML $twiml, VoiceCall $call, VoiceReceptionist $settings, string $say): TwiML
    {
        return $twiml->gather([
            'input' => 'speech',
            'action' => route('webhooks.voice.ai', $call),
            'method' => 'POST',
            'language' => 'en-US',
            'speechModel' => 'phone_call',
            'enhanced' => true,
            'speechTimeout' => 'auto',
            'timeout' => 6,
            'actionOnEmptyResult' => true,
        ], $say, $settings->voiceName());
    }

    /** Ends the call kindly and leaves the team a call-back task. */
    private function wrapUp(VoiceCall $call, Conversation $conversation, VoiceReceptionist $settings, string $goodbye, string $task): string
    {
        $assistant = AiAssistant::for($conversation->organization);
        $this->tools->run('create_follow_up', ['summary' => $task, 'call_back' => true], $conversation, $assistant, new ToolOutcome);
        $this->said($conversation, $goodbye);

        return TwiML::make()->say($goodbye, $settings->voiceName())->hangup()->xml();
    }

    private function heard(Conversation $conversation, string $text): void
    {
        $conversation->messages()->create([
            'organization_id' => $conversation->organization_id, 'direction' => Message::IN, 'author_type' => 'customer',
            'body' => mb_substr($text, 0, 2000), 'status' => 'received', 'sent_at' => now(),
        ]);
        $conversation->forceFill(['last_message_at' => now(), 'last_inbound_at' => now(), 'unread_count' => $conversation->unread_count + 1])->save();
    }

    private function said(Conversation $conversation, string $text, ?AiRun $run = null): void
    {
        $conversation->messages()->create([
            'organization_id' => $conversation->organization_id, 'direction' => Message::OUT, 'author_type' => 'ai',
            'body' => mb_substr($text, 0, 2000), 'status' => 'sent', 'sent_at' => now(), 'ai_run_id' => $run?->id,
        ]);
        $conversation->forceFill(['last_message_at' => now()])->save();
    }

    /** Text a voice can read: no markdown, no links, not too long. */
    private function speakable(string $text): string
    {
        $text = preg_replace('~https?://\S+~', 'our website', $text) ?? $text;
        $text = str_replace(['**', '__', '#', '`', '*', '•'], '', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return Str::limit($text, 600, '');
    }

    /**
     * @param  list<array{name: string, input: array<string, mixed>, ok: bool, summary: string}>  $calls
     * @param  array{input: int, output: int, cache_read: int}  $usage
     */
    private function log(Conversation $conversation, array $calls, array $usage, int $steps, string $status, ?string $reason): AiRun
    {
        return AiRun::create([
            'organization_id' => $conversation->organization_id, 'conversation_id' => $conversation->id, 'mode' => 'auto',
            'model' => $this->ai->model(), 'status' => $status, 'reason' => $reason, 'steps' => $steps,
            'input_tokens' => $usage['input'], 'output_tokens' => $usage['output'], 'cache_read_tokens' => $usage['cache_read'],
            'tool_calls' => $calls ?: null,
        ]);
    }

    private function businessName(Organization $organization): string
    {
        return BusinessProfile::query()->forOrganization($organization)->value('display_name') ?: $organization->name;
    }
}
