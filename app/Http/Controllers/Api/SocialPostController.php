<?php

namespace App\Http\Controllers\Api;

use App\Actions\Social\PostWorkflow;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialPostResource;
use App\Http\Responses\ApiResponse;
use App\Models\SocialPost;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Social posts for the mobile app (docs/api.md, spec §41): see what's planned and approve from the phone.
 */
class SocialPostController extends Controller
{
    private const RELATIONS = ['targets.account', 'media', 'author:id,name'];

    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['upcoming', 'awaiting_approval', 'published', 'failed', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = SocialPost::query()->forOrganization($current->get())
            ->with(self::RELATIONS)
            ->tap(fn (Builder $q) => match ($request->query('status', 'upcoming')) {
                'awaiting_approval' => $q->where('status', SocialPostStatus::InReview)->orderBy('scheduled_at'),
                'published' => $q->whereIn('status', [SocialPostStatus::Published->value, SocialPostStatus::PartlyPublished->value])->latest('published_at'),
                'failed' => $q->whereIn('status', [SocialPostStatus::Failed->value, SocialPostStatus::PartlyPublished->value])->latest('updated_at'),
                'all' => $q->latest('id'),
                default => $q->whereIn('status', [SocialPostStatus::InReview->value, SocialPostStatus::Scheduled->value, SocialPostStatus::Publishing->value])->orderBy('scheduled_at'),
            })
            ->paginate((int) $request->query('per_page', 25));

        return ApiResponse::success(
            ['posts' => SocialPostResource::collection($page->getCollection())->resolve($request)],
            meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    public function approve(Request $request, CurrentOrganization $current, PostWorkflow $workflow, string $ulid): JsonResponse
    {
        $post = $workflow->approve($this->find($current, $ulid), $request->user());

        return ApiResponse::success(['post' => (new SocialPostResource($post->load(self::RELATIONS)))->resolve($request)], 'Approved.');
    }

    public function requestChanges(Request $request, CurrentOrganization $current, PostWorkflow $workflow, string $ulid): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $post = $workflow->requestChanges($this->find($current, $ulid), $request->user(), $data['note']);

        return ApiResponse::success(['post' => (new SocialPostResource($post->load(self::RELATIONS)))->resolve($request)], 'Sent back with your note.');
    }

    private function find(CurrentOrganization $current, string $ulid): SocialPost
    {
        return SocialPost::query()->forOrganization($current->get())->where('ulid', $ulid)->firstOrFail();
    }
}
