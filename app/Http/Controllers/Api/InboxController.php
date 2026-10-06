<?php

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\SendReply;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The inbox for the mobile app (docs/api.md, spec §26): list, read and reply.
 */
class InboxController extends Controller
{
    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate([
            'filter' => ['nullable', Rule::in(['attention', 'ai', 'open', 'closed', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Conversation::query()->forOrganization($current->get())->with('customer:id,ulid,first_name,last_name,company')
            ->tap(fn (Builder $q) => match ($request->query('filter', 'attention')) {
                'ai' => $q->where('status', 'open')->where('needs_human', false)->where('ai_paused', false),
                'open' => $q->where('status', 'open'),
                'closed' => $q->where('status', 'closed'),
                'all' => $q,
                default => $q->where('status', 'open')->where('needs_human', true),
            })
            ->orderByDesc('last_message_at')->paginate((int) $request->query('per_page', 25));

        return ApiResponse::success(
            ['conversations' => $page->getCollection()->map(fn (Conversation $c) => $this->conversation($c))->values()],
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function show(CurrentOrganization $current, string $ulid): JsonResponse
    {
        $conversation = $this->find($current, $ulid);
        $messages = $conversation->messages()->whereNotIn('status', ['discarded', 'used'])->with('author:id,name')->get();
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return ApiResponse::success([
            'conversation' => $this->conversation($conversation),
            'messages' => $messages->map(fn (Message $m) => [
                'id' => $m->ulid,
                'direction' => $m->direction,
                'from' => $m->author_type,
                'author' => $m->author?->name,
                'text' => $m->body,
                'attachments' => $m->attachments ?? [],
                'is_note' => $m->is_note,
                'status' => $m->status,
                'error' => $m->status === 'failed' ? $m->error : null,
                'at' => ($m->sent_at ?? $m->created_at)?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function reply(Request $request, CurrentOrganization $current, SendReply $send, string $ulid): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:5000'], 'note' => ['nullable', 'boolean']]);
        $message = $send->handle($this->find($current, $ulid), $data['text'], $request->user(), note: (bool) ($data['note'] ?? false));

        if ($message->status === 'failed') {
            return ApiResponse::error((string) $message->error, 422, ['text' => [(string) $message->error]]);
        }

        return ApiResponse::success(['message' => ['id' => $message->ulid, 'status' => $message->status]], 'Sent.');
    }

    private function find(CurrentOrganization $current, string $ulid): Conversation
    {
        return Conversation::query()->forOrganization($current->get())->where('ulid', $ulid)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversation(Conversation $c): array
    {
        return [
            'id' => $c->ulid,
            'channel' => $c->channel->value,
            'name' => $c->displayName(),
            'customer_id' => $c->customer?->ulid,
            'status' => $c->status,
            'needs_human' => $c->needs_human,
            'ai_handling' => ! $c->needs_human && ! $c->ai_paused && $c->status === 'open',
            'unread' => $c->unread_count,
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'can_reply_until' => $c->channel->isMeta() && $c->last_inbound_at ? $c->last_inbound_at->copy()->addDays(7)->toIso8601String() : null,
        ];
    }
}
