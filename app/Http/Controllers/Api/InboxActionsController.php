<?php

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\RateAiReply;
use App\Actions\Inbox\SendReply;
use App\Exceptions\TelephonyUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Inbox\TextedPictures;
use App\Services\Telephony\Texting;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbox actions for the mobile app (API-7), the same as the web Inbox: start a text conversation,
 * assign, close and reopen, hand back to or take from the AI, mark read, act on the AI's suggested
 * replies and rate its answers. Texted pictures come as short-lived signed links.
 */
class InboxActionsController extends Controller
{
    public function __construct(private readonly Audit $audit) {}

    public function start(Request $request, CurrentOrganization $current, Texting $texting): JsonResponse
    {
        $organization = $current->get();
        $data = $request->validate(['to' => ['required', 'string', 'max:30'], 'text' => ['required', 'string', 'max:1600']]);
        try {
            $conversation = $texting->start($organization, $data['to'], $request->user(), $data['text']);
        } catch (TelephonyUnavailable $e) {
            return ApiResponse::error($e->getMessage(), 422, ['to' => [$e->getMessage()]]);
        }
        $last = $conversation->messages()->latest('id')->first();
        if ($last?->status === 'failed') {
            return ApiResponse::error('Not sent: '.$last->error, 422, ['text' => [(string) $last->error]]);
        }

        return ApiResponse::success(['conversation' => ['id' => $conversation->ulid], 'message' => ['id' => $last?->ulid, 'status' => $last?->status]], 'Text sent.', 201);
    }

    public function assignees(CurrentOrganization $current): JsonResponse
    {
        $members = $current->get()->members()->wherePivot('status', 'active')->where('users.is_active', true)->orderBy('users.name')->get(['users.id', 'users.name']);

        return ApiResponse::success(['assignees' => $members->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()]);
    }

    public function assign(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        $organization = $current->get();
        $conversation = $this->find($organization, $ulid);
        $data = $request->validate(['user_id' => ['present', 'nullable', 'integer']]);
        $member = $data['user_id'] === null ? null
            : $organization->members()->wherePivot('status', 'active')->whereKey((int) $data['user_id'])->first();
        if ($data['user_id'] !== null && ! $member) {
            return ApiResponse::error('Choose someone on your team.', 422, ['user_id' => ['Choose someone on your team.']]);
        }
        $conversation->forceFill(['assigned_to_user_id' => $member?->id])->save();
        $this->audit->record('inbox.assigned', $conversation, new: ['assigned_to' => $member?->name], organization: $organization, label: $conversation->displayName());

        return ApiResponse::success(['assigned_to' => $member ? ['id' => $member->id, 'name' => $member->name] : null]);
    }

    public function close(CurrentOrganization $current, string $ulid): JsonResponse
    {
        return $this->setStatus($current->get(), $ulid, closing: true);
    }

    public function reopen(CurrentOrganization $current, string $ulid): JsonResponse
    {
        return $this->setStatus($current->get(), $ulid, closing: false);
    }

    /** Let the assistant answer this conversation again (`on: true`), or keep it for people only. */
    public function ai(Request $request, CurrentOrganization $current, string $ulid): JsonResponse
    {
        $organization = $current->get();
        $conversation = $this->find($organization, $ulid);
        $on = $request->validate(['on' => ['required', 'boolean']])['on'];
        $conversation->forceFill(['ai_paused' => ! $on, 'needs_human' => $on ? false : $conversation->needs_human])->save();
        $this->audit->record($on ? 'inbox.ai_resumed' : 'inbox.ai_paused', $conversation, organization: $organization, label: $conversation->displayName());

        return ApiResponse::success(['ai_paused' => $conversation->ai_paused, 'needs_human' => $conversation->needs_human]);
    }

    public function read(CurrentOrganization $current, string $ulid): JsonResponse
    {
        $conversation = $this->find($current->get(), $ulid);
        $conversation->forceFill(['unread_count' => 0])->save();

        return ApiResponse::success(['unread' => 0]);
    }

    /** Send the AI's suggested reply as it is: a person approves it by sending. */
    public function sendDraft(CurrentOrganization $current, SendReply $send, Request $request, string $ulid, string $message): JsonResponse
    {
        $conversation = $this->find($current->get(), $ulid);
        $draft = $this->draft($conversation, $message);
        try {
            $sent = $send->handle($conversation, (string) $draft->body, $request->user());
        } catch (ValidationException $e) {
            return ApiResponse::error(collect($e->errors())->flatten()->first() ?? 'Not sent.', 422, $e->errors());
        }
        $draft->forceFill(['status' => 'used'])->save();
        if ($sent->status === 'failed') {
            return ApiResponse::error((string) $sent->error, 422, ['text' => [(string) $sent->error]]);
        }

        return ApiResponse::success(['message' => ['id' => $sent->ulid, 'status' => $sent->status]], 'Sent.');
    }

    public function discardDraft(CurrentOrganization $current, string $ulid, string $message): JsonResponse
    {
        $this->draft($this->find($current->get(), $ulid), $message)->forceFill(['status' => 'discarded'])->save();

        return ApiResponse::success(null, 'Suggestion discarded.');
    }

    /** Helpful or not, with an optional correction that becomes a guideline for the owner to approve (D39). */
    public function rate(Request $request, CurrentOrganization $current, RateAiReply $rate, string $ulid, string $message): JsonResponse
    {
        $conversation = $this->find($current->get(), $ulid);
        $data = $request->validate(['rating' => ['required', Rule::in(['helpful', 'unhelpful'])], 'correction' => ['nullable', 'string', 'max:1000']]);
        $reply = Message::query()->forOrganization($current->get())->where('conversation_id', $conversation->id)->where('ulid', $message)->firstOrFail();
        try {
            $rate->handle($reply, $request->user(), $data['rating'], $data['correction'] ?? null);
        } catch (ValidationException $e) {
            return ApiResponse::error(collect($e->errors())->flatten()->first() ?? 'Can\'t rate this.', 422, $e->errors());
        }

        return ApiResponse::success(null, filled($data['correction'] ?? null) ? 'Thanks. It\'s saved as a guideline for the owner to approve.' : 'Thanks for the feedback.');
    }

    /** A texted picture behind a signed link; still checks the person may read this business's inbox. */
    public function media(Request $request, string $organization, string $file): Response
    {
        $business = Organization::query()->where('ulid', $organization)->first();
        $user = User::query()->find((int) $request->query('user'));
        abort_unless($business && $user && $user->is_active && $user->hasPermissionIn('messages.view', $business), 404);
        abort_unless(preg_match('/^[0-9a-z]{26}\.(jpg|png|gif|webp|heic)$/', $file) === 1, 404);
        $path = TextedPictures::path($business->ulid, $file);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function setStatus(Organization $organization, string $ulid, bool $closing): JsonResponse
    {
        $conversation = $this->find($organization, $ulid);
        $conversation->forceFill([
            'status' => $closing ? 'closed' : 'open',
            'closed_at' => $closing ? now() : null,
            'needs_human' => $closing ? false : $conversation->needs_human,
            'unread_count' => $closing ? 0 : $conversation->unread_count,
        ])->save();
        $this->audit->record($closing ? 'inbox.closed' : 'inbox.reopened', $conversation, organization: $organization, label: $conversation->displayName());

        return ApiResponse::success(['status' => $conversation->status]);
    }

    private function find(Organization $organization, string $ulid): Conversation
    {
        return Conversation::query()->forOrganization($organization)->where('ulid', $ulid)->firstOrFail();
    }

    private function draft(Conversation $conversation, string $ulid): Message
    {
        return Message::query()->where('conversation_id', $conversation->id)->where('ulid', $ulid)
            ->where('author_type', 'ai')->where('status', 'draft')->firstOrFail();
    }
}
