<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailable;
use App\Jobs\SyncCalendarConnection;
use App\Livewire\Client\Business\Calendars;
use App\Models\BusinessHour;
use App\Models\CalendarBusyBlock;
use App\Models\CalendarConnection;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CalendarDisconnected;
use App\Services\Calendar\CalendarSync;
use App\Services\Scheduling\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P3-1 — Google / Microsoft calendar sync (spec §17–19, CAL-01..06). All provider traffic is faked.
 */
class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 08:00', self::TZ));
        config([
            'calendar.providers.google.client_id' => 'google-id', 'calendar.providers.google.client_secret' => 'google-secret',
            'calendar.providers.microsoft.client_id' => 'ms-id', 'calendar.providers.microsoft.client_secret' => 'ms-secret',
            'app.url' => 'https://app.surehelp.test',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['timezone' => self::TZ]);
        foreach ([1, 2, 3, 4, 5] as $day) {
            BusinessHour::create(['organization_id' => $organization->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '18:00']);
        }

        return [$owner, $organization->fresh()];
    }

    private function connection(Organization $organization, string $provider = 'google', array $extra = []): CalendarConnection
    {
        return CalendarConnection::create(array_merge([
            'organization_id' => $organization->id, 'provider' => $provider, 'account_email' => 'dana@rivera.test',
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->addHour(),
            'calendars' => [['id' => 'primary-cal', 'name' => 'Dana', 'primary' => true, 'can_write' => true]],
            'write_calendar_id' => 'primary-cal', 'busy_calendar_ids' => ['primary-cal'],
        ], $extra));
    }

    private function googleFakes(array $events = [], array $extra = []): void
    {
        Http::fake($extra + [
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600, 'scope' => 'calendar']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'dana@rivera.test']),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
                ['id' => 'primary-cal', 'summary' => 'Dana', 'primary' => true, 'accessRole' => 'owner'],
                ['id' => 'family', 'summary' => 'Family', 'accessRole' => 'reader'],
            ]]),
            'www.googleapis.com/calendar/v3/calendars/*/events/watch' => Http::response(['id' => 'chan-1', 'resourceId' => 'res-1', 'expiration' => (string) now()->addDays(7)->getTimestampMs()]),
            'www.googleapis.com/calendar/v3/calendars/*/events?*' => Http::response(['items' => $events]),
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['id' => 'evt-1', 'etag' => '"e1"']),
            'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response(['id' => 'evt-1', 'etag' => '"e2"']),
            'www.googleapis.com/calendar/v3/channels/stop' => Http::response([], 204),
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);
    }

    public function test_owner_connects_google_with_least_privilege_and_busy_times_sync(): void
    {
        [$owner, $org] = $this->business();
        $this->googleFakes([
            ['id' => 'dentist', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-10-06T11:00:00-05:00'], 'end' => ['dateTime' => '2026-10-06T12:00:00-05:00']],
            ['id' => 'lunch-free', 'status' => 'confirmed', 'transparency' => 'transparent', 'start' => ['dateTime' => '2026-10-06T13:00:00-05:00'], 'end' => ['dateTime' => '2026-10-06T14:00:00-05:00']],
            ['id' => 'gone', 'status' => 'cancelled', 'start' => ['dateTime' => '2026-10-06T15:00:00-05:00'], 'end' => ['dateTime' => '2026-10-06T16:00:00-05:00']],
        ]);

        $this->actingAs($owner)->get(route('app.business.calendars'))->assertOk()
            ->assertSee('Connect Google Calendar')->assertSee('We never read event details');

        $redirect = $this->get(route('app.integrations.calendar.connect', 'google'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $redirect);
        parse_str(parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertStringContainsString('calendar.events', $query['scope']);
        $this->assertStringNotContainsString('auth/calendar ', $query['scope'].' ', 'never the full calendar scope');

        $this->get(route('app.integrations.calendar.callback', ['provider' => 'google', 'state' => $query['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('app.business.calendars'));

        $connection = CalendarConnection::withoutGlobalScopes()->sole();
        $this->assertSame('dana@rivera.test', $connection->account_email);
        $this->assertSame('access-new', $connection->access_token);
        $this->assertNotSame('access-new', DB::table('calendar_connections')->value('access_token'), 'tokens are encrypted at rest');
        $this->assertSame('primary-cal', $connection->write_calendar_id);
        $this->assertSame(['primary-cal'], $connection->busy_calendar_ids);
        $this->assertDatabaseHas('audit_logs', ['action' => 'calendar.connected', 'organization_id' => $org->id]);
        $this->assertSame('chan-1', $connection->push_channels[0]['id'], 'push notifications set up over HTTPS');

        // Only the real busy event is mirrored, as times only.
        $block = CalendarBusyBlock::withoutGlobalScopes()->sole();
        $this->assertSame('dentist', $block->external_event_id);
        $this->assertSame('2026-10-06 16:00:00', $block->starts_at->utc()->format('Y-m-d H:i:s'));

        // The token never reaches the browser.
        $this->get(route('app.business.calendars'))->assertOk()->assertSee('dana@rivera.test')->assertDontSee('access-new')->assertDontSee('refresh-new');
    }

    public function test_forged_or_stale_callbacks_are_refused(): void
    {
        [$owner] = $this->business();
        $this->googleFakes();

        $this->actingAs($owner)->get(route('app.integrations.calendar.connect', 'google'));
        $this->get(route('app.integrations.calendar.callback', ['provider' => 'google', 'state' => 'forged', 'code' => 'x']))
            ->assertRedirect(route('app.business.calendars'))->assertSessionHas('error');
        $this->assertSame(0, CalendarConnection::withoutGlobalScopes()->count());

        // Denied on the consent screen.
        $location = $this->get(route('app.integrations.calendar.connect', 'google'))->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->get(route('app.integrations.calendar.callback', ['provider' => 'google', 'state' => $query['state'], 'error' => 'access_denied']))->assertSessionHas('error');
        $this->assertSame(0, CalendarConnection::withoutGlobalScopes()->count());

        // Staff can't connect; an unconfigured provider can't be started.
        [, $org] = $this->business();
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff)->get(route('app.integrations.calendar.connect', 'google'))->assertForbidden();
        config(['calendar.providers.microsoft.client_id' => null]);
        $this->actingAs($owner)->get(route('app.integrations.calendar.connect', 'microsoft'))->assertNotFound();
        $this->get(route('app.business.calendars'))->assertSee('Coming soon');
    }

    public function test_external_busy_time_blocks_free_times_and_bookings(): void
    {
        [, $org] = $this->business();
        $connection = $this->connection($org, extra: ['write_calendar_id' => null]);
        CalendarBusyBlock::create(['organization_id' => $org->id, 'calendar_connection_id' => $connection->id, 'calendar_id' => 'primary-cal', 'external_event_id' => 'x',
            'starts_at' => CarbonImmutable::parse('2026-10-06 10:00', self::TZ), 'ends_at' => CarbonImmutable::parse('2026-10-06 12:00', self::TZ)]);

        $slots = array_map(fn ($s) => $s->format('H:i'), app(Availability::class)->slots($org, '2026-10-06', 60));
        $this->assertNotContains('10:00', $slots);
        $this->assertNotContains('11:30', $slots);
        $this->assertContains('12:00', $slots);
        $this->assertContains('09:00', $slots);

        try {
            app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse('2026-10-06 11:00', self::TZ)]);
            $this->fail('should clash with the busy time');
        } catch (SlotUnavailable $e) {
            $this->assertSame("That time is busy in the business's Google Calendar.", $e->describe());
            $this->assertSame('12:00', $e->suggestions[0]->format('H:i'));
        }
    }

    public function test_appointments_are_written_moved_and_removed_without_overwriting_outside_edits(): void
    {
        [$owner, $org] = $this->business();
        $this->connection($org);
        $this->googleFakes();

        $appointment = app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse('2026-10-06 09:00', self::TZ), 'title' => 'Water heater repair']);
        $appointment->refresh();
        $ref = collect($appointment->external_refs)->first();
        $this->assertSame('evt-1', $ref['event_id']);
        $this->assertSame('"e1"', $ref['etag']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/calendars/primary-cal/events')
            && $r['summary'] === 'Water heater repair' && $r['start']['timeZone'] === self::TZ
            && $r['extendedProperties']['private']['surehelpAppointment'] === $appointment->ulid);

        app(RescheduleAppointment::class)->handle($appointment, CarbonImmutable::parse('2026-10-06 10:00', self::TZ), null, $owner);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->hasHeader('If-Match', '"e1"'));
        $this->assertSame('"e2"', collect($appointment->fresh()->external_refs)->first()['etag']);

        // Someone edited the event in Google: we stop touching it and flag it.
        Http::swap(new Factory);
        Http::fake(['www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response(['error' => 'precondition'], 412)]);
        app(RescheduleAppointment::class)->handle($appointment->fresh(), CarbonImmutable::parse('2026-10-06 11:00', self::TZ), null, $owner);
        $this->assertTrue(collect($appointment->fresh()->external_refs)->first()['conflict']);
        $this->actingAs($owner)->get(route('app.business.calendars'))->assertSee('changed directly in your calendar');
    }

    public function test_cancelling_removes_the_event_and_our_own_events_are_not_busy(): void
    {
        [$owner, $org] = $this->business();
        $connection = $this->connection($org);
        $this->googleFakes([
            ['id' => 'evt-1', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-10-06T09:00:00-05:00'], 'end' => ['dateTime' => '2026-10-06T10:00:00-05:00']],
        ]);

        $appointment = app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse('2026-10-06 09:00', self::TZ)]);
        $this->assertSame(0, app(CalendarSync::class)->pullBusy($connection->fresh()), 'our own appointment is not mirrored back as busy');

        app(ChangeAppointmentStatus::class)->handle($appointment->fresh(), AppointmentStatus::Cancelled, $owner);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/evt-1'));
        $this->assertNull($appointment->fresh()->external_refs);
    }

    public function test_lost_access_flags_the_connection_and_warns_the_business_and_agents(): void
    {
        [$owner, $org] = $this->business();
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($agent);
        $connection = $this->connection($org, extra: ['token_expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->assertSame(0, app(CalendarSync::class)->pullBusy($connection));
        $this->assertSame(CalendarConnection::STATUS_NEEDS_REAUTH, $connection->fresh()->status);
        Notification::assertSentTo($owner, CalendarDisconnected::class);

        // Told once, not on every sync.
        app(CalendarSync::class)->pullBusy($connection->fresh());
        Notification::assertSentToTimes($owner, CalendarDisconnected::class, 1);

        $this->actingAs($owner)->get(route('app.calendar'))->assertSee('needs reconnecting');
        $this->actingAs($agent)->get(route('agent.businesses.show', $org))->assertSee('Calendar not synced');
    }

    public function test_microsoft_busy_times_skip_free_and_cancelled_events(): void
    {
        [, $org] = $this->business();
        $connection = $this->connection($org, 'microsoft', ['calendars' => [['id' => 'AAMk', 'name' => 'Calendar', 'primary' => true, 'can_write' => true]], 'write_calendar_id' => 'AAMk', 'busy_calendar_ids' => ['AAMk']]);
        Http::fake(['graph.microsoft.com/v1.0/me/calendars/AAMk/calendarView*' => Http::response(['value' => [
            ['id' => 'm1', 'showAs' => 'busy', 'isCancelled' => false, 'isAllDay' => false, 'start' => ['dateTime' => '2026-10-06T15:00:00.0000000'], 'end' => ['dateTime' => '2026-10-06T16:00:00.0000000']],
            ['id' => 'm2', 'showAs' => 'free', 'isCancelled' => false, 'start' => ['dateTime' => '2026-10-06T17:00:00'], 'end' => ['dateTime' => '2026-10-06T18:00:00']],
            ['id' => 'm3', 'showAs' => 'busy', 'isCancelled' => true, 'start' => ['dateTime' => '2026-10-06T19:00:00'], 'end' => ['dateTime' => '2026-10-06T20:00:00']],
        ]])]);

        $this->assertSame(1, app(CalendarSync::class)->pullBusy($connection));
        $this->assertSame('10:00', CalendarBusyBlock::withoutGlobalScopes()->sole()->starts_at->setTimezone(self::TZ)->format('H:i'));
        Http::assertSent(fn (Request $r) => $r->hasHeader('Prefer', 'outlook.timezone="UTC"'));
    }

    public function test_webhooks_only_trigger_a_sync_with_the_right_secret(): void
    {
        [, $org] = $this->business();
        $google = $this->connection($org, extra: ['push_secret' => 'g-secret', 'push_channels' => [['calendar_id' => 'primary-cal', 'id' => 'chan-1', 'resource_id' => 'r', 'expires_at' => now()->addDays(5)->toIso8601String()]]]);
        $microsoft = $this->connection($org, 'microsoft', ['push_secret' => 'm-secret', 'push_channels' => [['calendar_id' => 'AAMk', 'id' => 'sub-1', 'resource_id' => null, 'expires_at' => now()->addDays(2)->toIso8601String()]]]);
        Bus::fake([SyncCalendarConnection::class]);

        $this->post('/api/webhooks/calendar/google', [], ['X-Goog-Channel-ID' => 'chan-1', 'X-Goog-Channel-Token' => 'wrong', 'X-Goog-Resource-State' => 'exists'])->assertNoContent();
        Bus::assertNotDispatched(SyncCalendarConnection::class);
        $this->post('/api/webhooks/calendar/google', [], ['X-Goog-Channel-ID' => 'chan-1', 'X-Goog-Channel-Token' => 'g-secret', 'X-Goog-Resource-State' => 'exists'])->assertNoContent();
        Bus::assertDispatched(SyncCalendarConnection::class, fn ($job) => $job->connectionId === $google->id);

        $this->post('/api/webhooks/calendar/microsoft?validationToken=hello%20graph')->assertOk()->assertSee('hello graph')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->postJson('/api/webhooks/calendar/microsoft', ['value' => [['subscriptionId' => 'sub-1', 'clientState' => 'nope']]])->assertStatus(202);
        Bus::assertNotDispatched(SyncCalendarConnection::class, fn ($job) => $job->connectionId === $microsoft->id);
        $this->postJson('/api/webhooks/calendar/microsoft', ['value' => [['subscriptionId' => 'sub-1', 'clientState' => 'm-secret']]])->assertStatus(202);
        Bus::assertDispatched(SyncCalendarConnection::class, fn ($job) => $job->connectionId === $microsoft->id);
    }

    public function test_settings_choose_calendars_and_disconnect(): void
    {
        [$owner, $org] = $this->business();
        $connection = $this->connection($org, extra: [
            'calendars' => [['id' => 'primary-cal', 'name' => 'Dana', 'primary' => true, 'can_write' => true], ['id' => 'family', 'name' => 'Family', 'primary' => false, 'can_write' => false]],
            'push_secret' => 's', 'push_channels' => [['calendar_id' => 'primary-cal', 'id' => 'chan-1', 'resource_id' => 'r', 'expires_at' => now()->addDays(5)->toIso8601String()]],
        ]);
        $this->googleFakes();
        $appointment = app(BookAppointment::class)->handle($org, ['starts_at' => CarbonImmutable::parse('2026-10-06 09:00', self::TZ)]);

        $this->actingAs($owner);
        Livewire::test(Calendars::class)
            ->set('writeCalendar.google', 'family')->call('save', 'google')->assertHasErrors('writeCalendar.google')
            ->set('writeCalendar.google', 'primary-cal')->set('busyCalendars.google', ['primary-cal', 'family'])->call('save', 'google')->assertHasNoErrors();
        $this->assertSame(['primary-cal', 'family'], $connection->fresh()->busy_calendar_ids);

        Livewire::test(Calendars::class)->call('disconnect', 'google');
        $this->assertSame(0, CalendarConnection::withoutGlobalScopes()->count());
        $this->assertNull($appointment->fresh()->external_refs);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'channels/stop'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'calendar.disconnected', 'organization_id' => $org->id]);
    }
}
