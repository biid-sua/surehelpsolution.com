<?php

namespace App\Http\Controllers\Chat;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Customers\MatchOrCreateCustomer;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailable;
use App\Http\Controllers\Controller;
use App\Models\BusinessService;
use App\Models\ChatWidget;
use App\Services\Billing\FeatureAccess;
use App\Services\Leads\LeadIntake;
use App\Services\Payments\CustomerPayments;
use App\Services\Rules\BusinessRules;
use App\Services\Scheduling\Availability;
use App\Support\CustomerLocale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Online booking and the contact form in the website snippet (spec §41B, D44). Public like the
 * chat: the widget key, its allowed websites and rate limits protect it. Bookings follow the same
 * rules as agents and the AI assistant (opening hours, notice, buffers, calendar busy times), and
 * every request lands in the CRM: a matched or new customer, an appointment or a follow-up task.
 */
class SiteToolsController extends Controller
{
    /** Bookings and contact forms one visitor can send to one business. */
    public const SUBMISSIONS_PER_HOUR = 5;

    public function services(Request $request, string $key): JsonResponse
    {
        $widget = $this->widget($request, $key, 'booking');

        return $this->json($request, $widget, [
            'timezone' => $widget->organization->timezoneOrDefault(),
            'services' => BusinessService::query()->forOrganization($widget->organization)->where('is_active', true)->where('is_bookable', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'duration_minutes', 'description'])
                ->map(fn (BusinessService $s) => ['id' => $s->id, 'name' => $s->name, 'minutes' => $s->duration_minutes ?? BookAppointment::DEFAULT_DURATION,
                    'description' => Str::limit((string) $s->description, 160)])->values(),
        ]);
    }

    public function slots(Request $request, Availability $availability, BusinessRules $rules, string $key): JsonResponse
    {
        $widget = $this->widget($request, $key, 'booking');
        $organization = $widget->organization;
        $timezone = $organization->timezoneOrDefault();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        [, $maxDays] = $rules->window($organization);
        $last = $today->addDays(min($maxDays ?? 60, 60));

        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $request->query('date'), $timezone) ?: $today;
        if ($date->lessThan($today) || $date->greaterThan($last)) {
            return $this->json($request, $widget, ['date' => $date->toDateString(), 'times' => []]);
        }

        $service = $this->service($widget, $request->query('service'));
        $times = $availability->slots($organization, $date, $service ? ($service->duration_minutes ?: BookAppointment::DEFAULT_DURATION) : BookAppointment::DEFAULT_DURATION, (int) $service?->buffer_minutes, serviceId: $service?->id);

        return $this->json($request, $widget, [
            'date' => $date->toDateString(),
            'last_date' => $last->toDateString(),
            'times' => array_map(fn (CarbonImmutable $t) => ['value' => $t->format('Y-m-d H:i'), 'label' => $t->format('g:i A')], array_slice($times, 0, 40)),
        ]);
    }

    public function book(Request $request, BookAppointment $book, MatchOrCreateCustomer $customers, string $key): JsonResponse
    {
        $widget = $this->widget($request, $key, 'booking');
        $data = $this->payload($request);
        if ($response = $this->guard($request, $widget, $data)) {
            return $response;
        }

        $organization = $widget->organization;
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', trim((string) ($data['starts_at'] ?? '')), $organization->timezoneOrDefault());
        if (! $start) {
            return $this->error($request, $widget, __('Choose a time.'));
        }
        $service = $this->service($widget, $data['service'] ?? null);
        $this->count($request, $widget);
        $customer = $customers->handle($organization, $this->contact($data), 'website')['customer'] ?? null;

        try {
            $appointment = $book->handle($organization, [
                'starts_at' => $start,
                'service_id' => $service?->id,
                'customer_id' => $customer?->id,
                'address' => Str::limit(trim((string) ($data['address'] ?? '')), 255, '') ?: null,
                'notes' => trim('Booked on the website. '.Str::limit(trim((string) ($data['notes'] ?? '')), 1000, '')),
                'status' => $widget->bookings_need_confirmation ? AppointmentStatus::Pending : AppointmentStatus::Confirmed,
            ], null, 'website', strict: true);
        } catch (SlotUnavailable $e) {
            return $this->json($request, $widget, [
                'message' => __('Sorry, that time was just taken. Please choose another.'),
                'times' => array_map(fn (CarbonImmutable $t) => ['value' => $t->format('Y-m-d H:i'), 'label' => $t->locale(app()->getLocale())->translatedFormat('D j M, g:i A')], $e->suggestions),
            ], 409);
        } catch (ValidationException $e) {
            return $this->error($request, $widget, (string) collect($e->errors())->flatten()->first());
        }

        $when = $appointment->starts_at->setTimezone($organization->timezoneOrDefault())->locale(app()->getLocale())->translatedFormat('l j F, g:i A');
        // A deposit, when the business takes one (D85). A problem with it never loses the booking.
        try {
            $deposit = app(CustomerPayments::class)->depositFor($appointment);
        } catch (\Throwable $e) {
            report($e);
            $deposit = null;
        }

        return $this->json($request, $widget, array_filter([
            'status' => $appointment->status->value,
            'message' => ($widget->bookings_need_confirmation
                ? __('Thanks! We\'ve received your request for :when. We\'ll confirm it shortly.', ['when' => $when])
                : __('You\'re booked for :when. See you then!', ['when' => $when])).($deposit ? ' '.__('Please pay the :amount deposit to secure your time.', ['amount' => $deposit->amount()]) : ''),
            'pay_url' => $deposit?->link(),
            'pay_label' => $deposit ? __('Pay :amount deposit', ['amount' => $deposit->amount()]) : null,
        ]), 201);
    }

    public function lead(Request $request, LeadIntake $intake, string $key): JsonResponse
    {
        $widget = $this->widget($request, $key, 'lead');
        $data = $this->payload($request);
        if ($response = $this->guard($request, $widget, $data)) {
            return $response;
        }

        $message = Str::limit(trim((string) ($data['message'] ?? '')), 2000, '');
        if ($message === '') {
            return $this->error($request, $widget, __('Tell us how we can help.'));
        }

        $this->count($request, $widget);
        // A customer, a call-back task, an alert for the team and, when the business has it on, an
        // instant text and email back (D109).
        $intake->receive($widget->organization, 'website', $this->contact($data) + [
            'message' => $message, 'page' => Str::limit((string) ($data['page'] ?? ''), 300, ''),
        ]);

        return $this->json($request, $widget, ['message' => __('Thanks! We\'ve got your message and will get back to you soon.')], 201);
    }

    /**
     * Shared checks for bookings and the contact form: name, a phone or email, the honeypot, and a
     * per-visitor limit so the form can't flood the business's task list (accepted submissions count).
     *
     * @param  array<string, mixed>  $data
     */
    private function guard(Request $request, ChatWidget $widget, array $data): ?JsonResponse
    {
        if (filled($data['website'] ?? null)) {
            return $this->json($request, $widget, ['message' => __('Thanks!')], 201);   // a bot filled the hidden field: pretend it worked
        }

        $limiter = 'site-form:'.$widget->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiter, self::SUBMISSIONS_PER_HOUR)) {
            return $this->error($request, $widget, __('You\'ve sent several requests already. Please call us instead, or try again later.'), 429);
        }

        $contact = $this->contact($data);
        if ($contact['name'] === null) {
            return $this->error($request, $widget, __('Please tell us your name.'));
        }
        if ($contact['phone'] === null && $contact['email'] === null) {
            return $this->error($request, $widget, __('Please give us a phone number or an email address.'));
        }

        return null;
    }

    /** Counts an accepted submission towards the visitor's hourly limit. */
    private function count(Request $request, ChatWidget $widget): void
    {
        RateLimiter::hit('site-form:'.$widget->id.':'.$request->ip(), 3600);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: ?string, phone: ?string, email: ?string}
     */
    private function contact(array $data): array
    {
        $phone = Str::limit(trim((string) ($data['phone'] ?? '')), 40, '');

        return [
            'name' => Str::limit(trim(strip_tags((string) ($data['name'] ?? ''))), 100, '') ?: null,
            'phone' => preg_match('/\d{7,}/', (string) preg_replace('/\D+/', '', $phone)) ? $phone : null,
            'email' => filter_var(trim((string) ($data['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null,
        ];
    }

    private function service(ChatWidget $widget, mixed $id): ?BusinessService
    {
        return filled($id) && ctype_digit((string) $id)
            ? BusinessService::query()->forOrganization($widget->organization)->where('is_active', true)->where('is_bookable', true)->find((int) $id)
            : null;
    }

    private function widget(Request $request, string $key, string $feature): ChatWidget
    {
        $widget = ChatWidget::withoutGlobalScopes()->where('public_key', $key)->where('is_enabled', true)->with('organization')->firstOrFail();
        abort_unless($widget->organization->isServing(), 404);   // paused or cancelled service: the website tools stay hidden (D45)
        abort_unless($widget->allowsOrigin(ChatWidget::pageOrigin($request)), 403, 'This website may not use this widget.');
        abort_unless($widget->offers($feature), 404);
        abort_unless(app(FeatureAccess::class)->allows($widget->organization, 'website_tools'), 404);   // D46
        CustomerLocale::apply($widget->organization, (string) $request->query('lang'));   // D92

        return $widget;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $json = json_decode((string) $request->getContent(), true);

        return is_array($json) ? $json : $request->all();
    }

    private function error(Request $request, ChatWidget $widget, string $message, int $status = 422): JsonResponse
    {
        return $this->json($request, $widget, ['message' => $message], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(Request $request, ChatWidget $widget, array $data, int $status = 200): JsonResponse
    {
        $response = response()->json($data, $status);
        $origin = $request->headers->get('Origin');
        if ($origin && $widget->allowsOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
