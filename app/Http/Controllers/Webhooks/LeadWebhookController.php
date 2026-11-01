<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadSettings;
use App\Services\Billing\FeatureAccess;
use App\Services\Leads\LeadIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A business's lead link (D109): a lead source sends new leads here through a connector such as
 * Zapier or Make (Facebook lead ads, Google, Angi, Thumbtack, Yelp, Nextdoor…). The secret in the
 * link is the business's own; JSON or form fields, with the field names those sources use.
 */
class LeadWebhookController extends Controller
{
    private const NAME = ['name', 'full_name', 'fullname', 'customer_name', 'contact_name', 'lead_name'];

    private const FIRST = ['first_name', 'firstname', 'given_name'];

    private const LAST = ['last_name', 'lastname', 'family_name', 'surname'];

    private const PHONE = ['phone', 'phone_number', 'phonenumber', 'mobile', 'mobile_phone', 'customer_phone', 'contact_phone', 'tel'];

    private const EMAIL = ['email', 'email_address', 'customer_email', 'contact_email'];

    private const MESSAGE = ['message', 'comments', 'comment', 'details', 'description', 'notes', 'job_description', 'request', 'service', 'project'];

    public function __invoke(Request $request, LeadIntake $intake, FeatureAccess $features, string $token): JsonResponse
    {
        $settings = LeadSettings::withoutGlobalScopes()->where('webhook_token', $token)->with('organization')->first();
        $organization = $settings?->organization;
        if (! $organization || ! $organization->isServing() || ! $features->allows($organization, 'lead_response')) {
            return response()->json(['ok' => false, 'message' => 'This lead link isn\'t active.'], 404);
        }

        $json = json_decode((string) $request->getContent(), true);
        $data = array_change_key_case(Arr::dot(is_array($json) ? $json : $request->all()));
        $flat = collect($data)->mapWithKeys(fn ($v, $k) => [Str::afterLast((string) $k, '.') => $v])->all();   // nested fields by their own name

        $name = $this->first($flat, self::NAME) ?? trim(($this->first($flat, self::FIRST) ?? '').' '.($this->first($flat, self::LAST) ?? '')) ?: null;
        $phone = $this->first($flat, self::PHONE);
        $email = $this->first($flat, self::EMAIL);
        if (! $phone && ! $email) {
            return response()->json(['ok' => false, 'message' => 'Send at least a phone number or an email.'], 422);
        }

        $source = Str::lower((string) ($request->query('source') ?? $flat['source'] ?? $flat['lead_source'] ?? 'other'));
        $source = collect(array_keys(Lead::SOURCES))->first(fn ($key) => str_contains($source, $key)) ?? 'other';
        $known = [...self::NAME, ...self::FIRST, ...self::LAST, ...self::PHONE, ...self::EMAIL, ...self::MESSAGE, 'source', 'lead_source'];
        $extra = collect($flat)->except($known)->filter(fn ($v) => is_scalar($v) && trim((string) $v) !== '')
            ->map(fn ($v) => Str::limit((string) $v, 200, ''))->take(15)->all();

        $lead = $intake->receive($organization, $source, [
            'name' => $name, 'phone' => $phone, 'email' => $email, 'message' => $this->first($flat, self::MESSAGE), 'details' => $extra,
        ]);

        return response()->json(['ok' => true, 'lead' => $lead->ulid], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function first(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
