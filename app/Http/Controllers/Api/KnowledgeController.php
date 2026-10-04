<?php

namespace App\Http\Controllers\Api;

use App\Enums\KnowledgeType;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BusinessRule;
use App\Models\BusinessService;
use App\Models\KnowledgeItem;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The business's knowledge base and rules for the mobile app, read-only (docs/api.md, spec §22–23).
 */
class KnowledgeController extends Controller
{
    public function index(Request $request, CurrentOrganization $current): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::enum(KnowledgeType::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $items = KnowledgeItem::query()->forOrganization($current->get())
            ->where('is_active', true)
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->search((string) $request->query('search', ''))
            ->ordered()
            ->limit(500)
            ->get()
            ->map(fn (KnowledgeItem $item) => [
                'id' => $item->ulid,
                'type' => $item->type->value,
                'title' => $item->title,
                'content' => $item->content,
                'category' => $item->category,
                'visibility' => $item->visibility->value,
                'pinned' => $item->is_pinned,
                'updated_at' => $item->updated_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['items' => $items]);
    }

    public function rules(CurrentOrganization $current): JsonResponse
    {
        $organization = $current->get();
        $names = BusinessService::query()->forOrganization($organization)->withTrashed()->pluck('name', 'id')->all();

        $rules = BusinessRule::query()->forOrganization($organization)->orderBy('id')->get()
            ->map(fn (BusinessRule $rule) => [
                'id' => $rule->id,
                'type' => $rule->type->value,
                'description' => $rule->sentence($names),
                'enforced' => $rule->type->isEnforced(),
                'active' => $rule->is_active,
            ]);

        return ApiResponse::success(['rules' => $rules]);
    }
}
