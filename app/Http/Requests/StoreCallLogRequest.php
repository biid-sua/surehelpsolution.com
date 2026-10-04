<?php

namespace App\Http\Requests;

use App\Enums\EscalationPriority;
use App\Enums\EscalationType;
use App\Models\CallLog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Validation for a new call log, shared by the web agent workspace and /api/v1.
 */
class StoreCallLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['agent', 'admin'], true);
    }

    public function rules(): array
    {
        return [
            'client_id' => ['nullable', 'string', 'max:255'],
            'call_date' => ['required', 'date'],
            'call_time' => ['required'],
            'caller_name' => ['nullable', 'string', 'max:255'],
            'caller_phone' => ['nullable', 'string', 'max:20'],
            'caller_email' => ['nullable', 'email', 'max:255'],
            'reason_for_call' => ['required', 'string', 'max:255'],
            'call_outcome' => ['required', 'string', 'max:255'],
            'agent_name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(CallLog::STATUSES)],
            'service_request' => ['boolean'],
            'service_date' => ['nullable', 'date'],
            'service_window' => ['nullable', 'string', 'max:255'],
            'service_location' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            // Optional detail for outcomes in the "escalated" category (P2-4c, additive).
            'escalation_type' => ['nullable', Rule::enum(EscalationType::class)],
            'escalation_priority' => ['nullable', Rule::enum(EscalationPriority::class)],
        ];
    }

    /**
     * Keep the response shape both existing frontends already handle.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Access denied. Agent or Admin role required.',
        ], 403));
    }
}
