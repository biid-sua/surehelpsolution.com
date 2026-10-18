<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\QaReview;
use App\Models\ShiftRequest;
use App\Models\User;
use App\Services\Quality\QualityReviews;
use App\Services\Scheduling\ShiftRequests;
use App\Services\Telephony\AgentPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An agent's own work in the app: shift swap and time-off requests (API-13, D28), the quality reviews of
 * their calls (API-14, D27) and their phone availability (API-15, D60). Calls still ring the softphone on
 * a computer, so the app can only step away or come back while that softphone is online.
 */
class AgentWorkController extends Controller
{
    public function shiftRequests(Request $request): JsonResponse
    {
        $requests = ShiftRequest::query()->where('agent_id', $request->user()->id)
            ->where(fn ($q) => $q->where('status', ShiftRequest::PENDING)->orWhere('updated_at', '>=', now()->subDays(30)))
            ->with(['shift', 'swapWith:id,name'])->latest()->limit(30)->get();

        return ApiResponse::success([
            'requests' => $requests->map(fn (ShiftRequest $r) => $this->shiftRequest($r))->values(),
            'colleagues' => User::query()->where('role', 'agent')->where('is_active', true)->whereKeyNot($request->user()->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    public function requestShiftChange(Request $request, ShiftRequests $requests): JsonResponse
    {
        $agent = $request->user();
        abort_unless($agent->isAgent(), 403, 'Only agents ask for shift changes.');
        $type = $request->validate(['type' => ['required', Rule::in([ShiftRequest::SWAP, ShiftRequest::LEAVE])]])['type'];
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']] + ($type === ShiftRequest::SWAP
            ? ['shift_id' => ['required', 'integer'], 'swap_with_id' => ['nullable', 'integer']]
            : ['leave_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'leave_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:leave_from']]));

        $created = $type === ShiftRequest::SWAP
            ? $requests->requestSwap($agent, (int) $data['shift_id'], isset($data['swap_with_id']) ? (int) $data['swap_with_id'] : null, $data['reason'] ?? null)
            : $requests->requestLeave($agent, $data['leave_from'], $data['leave_until'], $data['reason'] ?? null);

        return ApiResponse::success(['request' => $this->shiftRequest($created->load(['shift', 'swapWith:id,name']))], 'Request sent. You\'ll hear back in the app and by email.', 201);
    }

    public function withdrawShiftRequest(Request $request, ShiftRequests $requests, string $ulid): JsonResponse
    {
        $shiftRequest = ShiftRequest::query()->where('agent_id', $request->user()->id)->where('ulid', $ulid)->firstOrFail();
        $requests->cancel($shiftRequest, $request->user());

        return ApiResponse::success(['request' => $this->shiftRequest($shiftRequest->fresh(['shift', 'swapWith:id,name']))], 'Request withdrawn.');
    }

    public function reviews(Request $request, QualityReviews $reviews): JsonResponse
    {
        $user = $request->user();
        $page = QaReview::query()->completed()->where('agent_user_id', $user->id)
            ->with(['call:id,call_id,caller_name,created_at', 'organization:id,name', 'reviewer:id,name'])->latest('reviewed_at')->paginate(20);

        return ApiResponse::success([
            'summary' => $reviews->summaryFor($user, now()->subDays(30)),
            'pass_score' => (int) config('quality.pass_score'),
            'reviews' => $page->getCollection()->map(fn (QaReview $r) => $this->presentReview($r, false))->values(),
        ], meta: ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]);
    }

    public function review(Request $request, string $ulid): JsonResponse
    {
        $review = QaReview::query()->completed()->where('agent_user_id', $request->user()->id)->where('ulid', $ulid)
            ->with(['call:id,call_id,caller_name,created_at', 'organization:id,name', 'reviewer:id,name'])->firstOrFail();

        return ApiResponse::success(['review' => $this->presentReview($review, true)]);
    }

    public function acknowledgeReview(Request $request, string $ulid): JsonResponse
    {
        $review = QaReview::query()->completed()->where('agent_user_id', $request->user()->id)->where('ulid', $ulid)->firstOrFail();
        $review->forceFill(['acknowledged_at' => $review->acknowledged_at ?? now()])->save();

        return ApiResponse::success(['acknowledged_at' => $review->acknowledged_at?->toIso8601String()], 'Thanks. Your reviewer can see you\'ve read it.');
    }

    public function presence(Request $request, AgentPresence $presence): JsonResponse
    {
        return ApiResponse::success($this->presenceState($request->user(), $presence));
    }

    /** Step away (`away`) or come back (`available`). Coming back needs the computer softphone online. */
    public function setPresence(Request $request, AgentPresence $presence): JsonResponse
    {
        $agent = $request->user();
        $status = $request->validate(['status' => ['required', Rule::in([AgentPresence::AVAILABLE, AgentPresence::AWAY])]])['status'];
        $current = $presence->status($agent);
        if ($current === AgentPresence::OFFLINE) {
            return ApiResponse::error('Your softphone is offline. Open it on your computer to take calls.', 409, extra: ['presence' => $this->presenceState($agent, $presence)]);
        }
        if ($current === AgentPresence::BUSY) {
            return ApiResponse::error('You\'re on a call. Change your status when it ends.', 409, extra: ['presence' => $this->presenceState($agent, $presence)]);
        }
        // Only the status changes: the app isn't the softphone, so it doesn't count as the softphone checking in.
        $agent->forceFill(['phone_status' => $status, 'phone_status_at' => now()])->save();

        return ApiResponse::success($this->presenceState($agent->fresh(), $presence), $status === AgentPresence::AWAY ? 'You\'re away. Calls won\'t ring you.' : 'You\'re ready for calls.');
    }

    /** @return array<string, mixed> */
    private function presenceState(User $agent, AgentPresence $presence): array
    {
        $status = $presence->status($agent);

        return [
            'status' => $status,
            'label' => AgentPresence::LABELS[$status] ?? $status,
            'softphone_online' => $status !== AgentPresence::OFFLINE,
            'since' => $agent->phone_status_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function shiftRequest(ShiftRequest $r): array
    {
        return [
            'id' => $r->ulid,
            'type' => $r->type,
            'status' => $r->status,
            'status_label' => ShiftRequest::STATUS_LABELS[$r->status] ?? $r->status,
            'shift' => $r->shift ? ['id' => $r->shift->id, 'starts_at' => $r->shift->start_datetime->toIso8601String(), 'ends_at' => $r->shift->end_datetime->toIso8601String()] : null,
            'swap_with' => $r->swapWith ? ['id' => $r->swapWith->id, 'name' => $r->swapWith->name] : null,
            'leave_from' => $r->leave_from?->toDateString(),
            'leave_until' => $r->leave_until?->toDateString(),
            'reason' => $r->reason,
            'decision_note' => $r->decision_note,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentReview(QaReview $r, bool $full): array
    {
        return [
            'id' => $r->ulid,
            'score' => $r->score,
            'passed' => (bool) $r->passed,
            'business' => $r->organization?->name,
            'call' => $r->call ? ['id' => $r->call->call_id, 'caller' => $r->call->caller_name, 'at' => $r->call->created_at?->toIso8601String()] : null,
            'reviewer' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'acknowledged_at' => $r->acknowledged_at?->toIso8601String(),
        ] + ($full ? [
            'scores' => array_map(fn (array $s, string $key) => ['key' => $key, 'label' => $s['label'], 'weight' => $s['weight'], 'critical' => $s['critical'], 'mark' => $s['mark'], 'mark_label' => QaReview::MARKS[$s['mark']] ?? $s['mark']], (array) $r->scores, array_keys((array) $r->scores)),
            'strengths' => $r->strengths,
            'improvements' => $r->improvements,
        ] : []);
    }
}
