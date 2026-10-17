<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Task;
use App\Services\Search\BusinessSearch;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Search across the business from the app (API-17), the same as the web search: customers, calls,
 * appointments and tasks the person may see. At least 2 characters.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current, BusinessSearch $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'limit' => ['nullable', 'integer', 'min:1', 'max:20']]);
        $results = $search->run($current->get(), $request->user(), $data['q'], (int) ($data['limit'] ?? 8));

        return ApiResponse::success(['results' => [
            'customers' => isset($results['customers']) ? $results['customers']->map(fn (Customer $c) => ['id' => $c->ulid, 'name' => $c->fullName(), 'phone' => $c->phone, 'email' => $c->email])->values() : null,
            'calls' => isset($results['calls']) ? $results['calls']->map(fn (CallLog $c) => ['id' => $c->call_id, 'caller' => $c->caller_name, 'phone' => $c->caller_phone, 'reason' => $c->reason_for_call, 'created_at' => $c->created_at?->toIso8601String()])->values() : null,
            'appointments' => isset($results['appointments']) ? $results['appointments']->map(fn (Appointment $a) => ['id' => $a->ulid, 'title' => $a->title, 'customer' => $a->customer?->fullName(), 'starts_at' => $a->starts_at->toIso8601String(), 'status' => $a->status->value])->values() : null,
            'tasks' => isset($results['tasks']) ? $results['tasks']->map(fn (Task $t) => ['id' => $t->ulid, 'title' => $t->title, 'customer' => $t->customer?->fullName(), 'status' => $t->status->value, 'due_at' => $t->due_at?->toIso8601String()])->values() : null,
        ]]);
    }
}
