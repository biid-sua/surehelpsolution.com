<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationEvent;
use App\Exceptions\TelephonyUnavailable;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Livewire\Account\Notifications as WorkforceNotifications;
use App\Livewire\Client\Settings\Notifications as BusinessNotifications;
use App\Models\NotificationPreference;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Account\NotificationPhone;
use App\Support\NotificationChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notification settings in the app (API-3): the same choices as the web settings pages, per event and
 * channel, quiet hours, the daily summary for business people, and the verified mobile number that
 * notification texts go to (D63).
 */
class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->state($request->user()->load('notificationPreferences')));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $events = $this->events($user);
        $channels = NotificationChannels::enabledKeys();
        $data = $request->validate([
            'events' => ['sometimes', 'array'],
            'events.*' => ['array'],
            'events.*.*' => ['string', Rule::in($channels)],
            'quiet_hours' => ['sometimes', 'nullable', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i', 'different:quiet_hours.start'],
            'daily_summary' => [$user->isClient() ? 'sometimes' : 'prohibited', Rule::in(['off', ...array_keys(BusinessNotifications::summaryTimes())])],
        ], ['quiet_hours.end.different' => 'Quiet hours need to end at a different time than they start.']);

        $known = array_map(fn (NotificationEvent $e) => $e->value, $events);
        if ($unknown = array_diff(array_keys($data['events'] ?? []), $known)) {
            return ApiResponse::error('Some of those notifications aren\'t available to you.', 422, ['events' => ['Unknown: '.implode(', ', $unknown)]]);
        }

        foreach ($data['events'] ?? [] as $event => $chosen) {
            NotificationPreference::updateOrCreate(['user_id' => $user->id, 'event' => $event], ['channels' => array_values(array_unique($chosen))]);
        }
        if (array_key_exists('quiet_hours', $data)) {
            $user->forceFill(['quiet_hours_start' => $data['quiet_hours']['start'] ?? null, 'quiet_hours_end' => $data['quiet_hours']['end'] ?? null]);
        }
        if (array_key_exists('daily_summary', $data)) {
            $user->forceFill(['daily_summary_at' => $data['daily_summary']]);
        }
        $user->save();

        return ApiResponse::success($this->state($user->fresh()->load('notificationPreferences')), 'Notification settings saved.');
    }

    public function sendPhoneCode(Request $request, NotificationPhone $phones): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        try {
            $phones->sendCode($request->user(), $data['phone']);
        } catch (TelephonyUnavailable $e) {
            return ApiResponse::error($e->getMessage(), 422, ['phone' => [$e->getMessage()]]);
        }

        return ApiResponse::success($this->phone($request->user()->fresh()), 'We texted a 6-digit code. It expires in 10 minutes.');
    }

    public function verifyPhone(Request $request, NotificationPhone $phones): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        if (! $phones->verify($request->user(), $data['code'])) {
            return ApiResponse::error('That code isn\'t right or has expired. Ask for a new one.', 422, ['code' => ['That code isn\'t right or has expired.']]);
        }

        return ApiResponse::success($this->phone($request->user()->fresh()), 'Your mobile number is verified.');
    }

    public function removePhone(Request $request, NotificationPhone $phones): JsonResponse
    {
        $phones->remove($request->user());

        return ApiResponse::success($this->phone($request->user()->fresh()), 'Notification texts are off.');
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        return [
            'channels' => collect(NotificationChannels::all())->map(fn (array $c, string $key) => ['key' => $key, 'label' => $c['label'], 'enabled' => $c['enabled']])->values(),
            'events' => array_map(fn (NotificationEvent $e) => [
                'key' => $e->value,
                'label' => $e->label(),
                'description' => $e->description(),
                'channels' => $user->notificationChannelsFor($e),
            ], $this->events($user)),
            'quiet_hours' => $user->quiet_hours_start ? ['start' => $user->quiet_hours_start, 'end' => $user->quiet_hours_end, 'timezone' => $user->timezoneOrDefault()] : null,
            'daily_summary' => $user->isClient() ? [
                'time' => $user->dailySummaryTime(),
                'options' => array_keys(BusinessNotifications::summaryTimes()),
            ] : null,
            'sms_phone' => $this->phone($user),
        ];
    }

    /** @return array{number: ?string, display: ?string, verified: bool, texts_available: bool} */
    private function phone(User $user): array
    {
        return [
            'number' => $user->sms_phone,
            'display' => $user->sms_phone ? PhoneNumber::pretty($user->sms_phone) : null,
            'verified' => $user->sms_phone !== null && $user->sms_phone_verified_at !== null,
            'texts_available' => NotificationChannels::enabled('sms'),
        ];
    }

    /**
     * The events this person can choose for, as on their web settings page.
     *
     * @return list<NotificationEvent>
     */
    private function events(User $user): array
    {
        if ($user->isClient()) {
            $organization = $user->primaryOrganization();

            return array_values(array_filter(NotificationEvent::available(),
                fn (NotificationEvent $e) => $e->requiredPermission() === null || $user->hasPermissionIn($e->requiredPermission(), $organization)));
        }

        return array_values(array_filter(WorkforceNotifications::EVENTS, fn (NotificationEvent $e) => $user->hasPermissionIn((string) $e->requiredPermission())));
    }
}
