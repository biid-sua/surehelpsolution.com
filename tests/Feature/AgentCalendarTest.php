<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Agent\Calendar;
use App\Models\AgentCalendarConnection;
use App\Models\AgentCalendarEvent;
use App\Models\AgentDutySchedule;
use App\Models\Appointment;
use App\Models\ShiftRequest;
use App\Models\User;
use App\Notifications\AgentCalendarDisconnected;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * My calendar for agents (D54): shifts, time off and bookings in the agent's own time; their own
 * Google or Microsoft calendar shown as busy and receiving their shifts; strictly their own data.
 */
class AgentCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-05 08:00', 'America/Chicago'));
        config([
            'calendar.providers.google.client_id' => 'google-id', 'calendar.providers.google.client_secret' => 'google-secret',
            'calendar.providers.microsoft.client_id' => null,
        ]);
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false, 'timezone' => 'America/Chicago', 'name' => 'Maria Agent']);
        $this->agent->syncRoles(['agent']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function shift(User $agent, string $start, string $end, array $extra = []): AgentDutySchedule
    {
        return AgentDutySchedule::create(array_merge([
            'agent_id' => $agent->id, 'title' => 'Front desk', 'shift_type' => 'morning', 'is_active' => true,
            'start_datetime' => CarbonImmutable::parse($start, 'America/Chicago')->utc(), 'end_datetime' => CarbonImmutable::parse($end, 'America/Chicago')->utc(),
        ], $extra));
    }

    private function connection(array $extra = []): AgentCalendarConnection
    {
        return AgentCalendarConnection::create(array_merge([
            'user_id' => $this->agent->id, 'provider' => 'google', 'account_email' => 'maria@gmail.test',
            'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->addHour(),
            'calendars' => [['id' => 'primary', 'name' => 'Maria', 'primary' => true, 'can_write' => true], ['id' => 'family', 'name' => 'Family', 'primary' => false, 'can_write' => false]],
            'busy_calendar_ids' => ['primary', 'family'], 'write_calendar_id' => 'primary', 'push_shifts' => true,
        ], $extra));
    }

    private function feed(array $query = []): array
    {
        return $this->actingAs($this->agent)->getJson(route('agent.calendar.events', $query + ['start' => '2026-10-04', 'end' => '2026-10-11']))->assertOk()->json();
    }

    public function test_my_calendar_shows_only_my_shifts_time_off_and_bookings_in_my_time(): void
    {
        $this->shift($this->agent, '2026-10-06 09:00', '2026-10-06 17:00');
        $this->shift($this->agent, '2026-10-07 00:00', '2026-10-07 23:59', ['shift_type' => 'off', 'title' => 'Off']);
        $this->shift($this->agent, '2026-10-08 09:00', '2026-10-08 17:00', ['is_active' => false]);
        $colleague = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->shift($colleague, '2026-10-06 09:00', '2026-10-06 17:00', ['title' => 'Colleague shift']);
        ShiftRequest::create(['agent_id' => $this->agent->id, 'type' => ShiftRequest::LEAVE, 'leave_from' => '2026-10-09', 'leave_until' => '2026-10-10', 'status' => ShiftRequest::APPROVED]);
        ShiftRequest::create(['agent_id' => $this->agent->id, 'type' => ShiftRequest::LEAVE, 'leave_from' => '2026-10-08', 'leave_until' => '2026-10-08', 'status' => ShiftRequest::PENDING]);

        $owner = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->assignAgent($this->agent);
        Appointment::create(['organization_id' => $org->id, 'title' => 'Drain repair', 'booked_by_user_id' => $this->agent->id,
            'starts_at' => CarbonImmutable::parse('2026-10-06 14:00', 'America/Chicago')->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-06 15:00', 'America/Chicago')->utc(),
            'blocked_until' => CarbonImmutable::parse('2026-10-06 15:00', 'America/Chicago')->utc(), 'timezone' => 'America/Chicago']);

        $events = collect($this->feed())->keyBy('title');
        $this->assertSame('2026-10-06T09:00:00', $events['Front desk']['start'], 'shown in the agent\'s own time');
        $this->assertArrayHasKey('Day off', $events->all());
        $this->assertSame(['start' => '2026-10-09', 'end' => '2026-10-11'], ['start' => $events['Time off']['start'], 'end' => $events['Time off']['end']]);
        $this->assertSame(1, $events->where('title', 'Time off')->count(), 'pending leave isn\'t shown');
        $this->assertArrayHasKey('Drain repair · '.$org->name, $events->all());
        $this->assertArrayNotHasKey('Colleague shift', $events->all());
        $this->assertCount(4, $events);

        // Only the sources asked for.
        $this->assertSame(['Front desk', 'Day off'], array_column($this->feed(['sources' => 'shifts']), 'title'));

        // Bookings for a company the agent no longer serves disappear.
        $org->agents()->detach($this->agent->id);
        $this->assertArrayNotHasKey('Drain repair · '.$org->name, collect($this->feed())->keyBy('title')->all());
    }

    public function test_my_own_calendar_shows_busy_times_without_titles(): void
    {
        $this->connection();
        Http::fake(['www.googleapis.com/calendar/v3/calendars/*/events?*' => Http::response(['items' => [
            ['id' => 'e1', 'summary' => 'Dentist (private)', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-10-06T12:00:00-05:00'], 'end' => ['dateTime' => '2026-10-06T13:00:00-05:00']],
            ['id' => 'e2', 'summary' => 'Free time', 'transparency' => 'transparent', 'start' => ['dateTime' => '2026-10-06T15:00:00-05:00'], 'end' => ['dateTime' => '2026-10-06T16:00:00-05:00']],
        ]])]);

        $busy = collect($this->feed(['sources' => 'google']));
        $this->assertSame(['Busy', 'Busy'], $busy->pluck('title')->all(), 'one busy block per chosen calendar');
        $this->assertSame('2026-10-06T12:00:00', $busy->first()['start']);
        $this->assertStringNotContainsString('Dentist', json_encode($busy->all()));
        $this->assertSame(['Maria', 'Family'], $busy->pluck('extendedProps.calendar')->all());
    }

    public function test_connecting_google_keeps_tokens_on_the_server_and_copies_shifts(): void
    {
        $this->shift($this->agent, '2026-10-06 09:00', '2026-10-06 17:00');
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-new', 'refresh_token' => 'refresh-new', 'expires_in' => 3600, 'scope' => 'calendar']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => 'maria@gmail.test']),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [['id' => 'primary', 'summary' => 'Maria', 'primary' => true, 'accessRole' => 'owner']]]),
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['id' => 'evt-1', 'etag' => '"e1"']),
        ]);

        $this->actingAs($this->agent);
        $redirect = $this->get(route('agent.calendar.connect', 'google'))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
        $this->assertSame(route('agent.calendar.callback', 'google'), $query['redirect_uri']);

        // A forged callback is refused.
        $this->get(route('agent.calendar.callback', ['provider' => 'google', 'state' => 'forged', 'code' => 'x']))->assertSessionHas('error');
        $redirect = $this->get(route('agent.calendar.connect', 'google'))->headers->get('Location');
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $query);
        $this->get(route('agent.calendar.callback', ['provider' => 'google', 'state' => $query['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('agent.calendar'))->assertSessionHas('success');

        $connection = AgentCalendarConnection::sole();
        $this->assertSame('access-new', $connection->access_token);
        $this->assertNotSame('access-new', \DB::table('agent_calendar_connections')->value('access_token'), 'tokens are encrypted at rest');
        $this->assertSame('primary', $connection->write_calendar_id);
        $this->assertSame('evt-1', AgentCalendarEvent::sole()->external_id, 'the shift was copied');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/calendars/primary/events') && $r['summary'] === 'SureHelp shift: Front desk');
        $this->get(route('agent.calendar'))->assertOk()->assertSee('maria@gmail.test')->assertDontSee('access-new');
    }

    public function test_copied_shifts_follow_changes_and_disappear_when_removed(): void
    {
        $connection = $this->connection();
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['id' => 'evt-1', 'etag' => '"e1"']),
            'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response(['id' => 'evt-1', 'etag' => '"e2"']),
        ]);

        $shift = $this->shift($this->agent, '2026-10-06 09:00', '2026-10-06 17:00'); // copied as it's planned
        $this->assertSame(1, $connection->events()->count());
        Http::assertSentCount(1);

        $shift->update(['end_datetime' => CarbonImmutable::parse('2026-10-06 18:00', 'America/Chicago')->utc()]);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && ! $r->hasHeader('If-Match'));

        // Unchanged shifts aren't sent again.
        $this->artisan('agent-calendars:sync')->assertSuccessful();
        Http::assertSentCount(2);

        $shift->update(['is_active' => false]);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertSame(0, $connection->events()->count());
    }

    public function test_settings_disconnect_and_lost_access(): void
    {
        $connection = $this->connection();
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*/events' => Http::response(['id' => 'evt-1']),
            'www.googleapis.com/calendar/v3/calendars/*/events/*' => Http::response([], 204),
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);
        $this->shift($this->agent, '2026-10-06 09:00', '2026-10-06 17:00');

        $this->actingAs($this->agent);
        Livewire::test(Calendar::class)
            ->set('settings.google.busy', ['primary', 'not-mine'])->set('settings.google.write', 'family')->call('save', 'google')->assertHasErrors('settings.google.write')
            ->set('settings.google.push', false)->call('save', 'google')->assertHasNoErrors();
        $this->assertSame(['primary'], $connection->fresh()->busy_calendar_ids);
        $this->assertSame(0, $connection->events()->count(), 'turning copying off removes our events');

        // Lost access: flagged, the agent is told, busy times stop.
        $connection->update(['token_expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->feed(['sources' => 'google']);
        $this->assertSame('needs_reauth', $connection->fresh()->status);
        Notification::assertSentTo($this->agent, AgentCalendarDisconnected::class);
        $this->get(route('agent.calendar'))->assertSee('Reconnect your Google Calendar');

        Livewire::test(Calendar::class)->call('disconnect', 'google');
        $this->assertSame(0, AgentCalendarConnection::query()->count());
    }

    public function test_it_is_mine_alone(): void
    {
        // Business owners have no agent calendar. (HTTP checks first: Livewire's test helper
        // changes the request pipeline for later requests in the same test.)
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        $this->actingAs($owner)->get(route('agent.calendar'))->assertForbidden();
        $this->actingAs($owner)->getJson(route('agent.calendar.events', ['start' => '2026-10-04', 'end' => '2026-10-11']))->assertForbidden();
        $this->get(route('agent.calendar.connect', 'google'))->assertForbidden();

        // Another agent's connection is never shown or changed.
        $colleague = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        AgentCalendarConnection::create(['user_id' => $colleague->id, 'provider' => 'google', 'access_token' => 'x', 'calendars' => []]);
        $this->actingAs($this->agent);
        $page = Livewire::test(Calendar::class)->assertDontSee('Connected as');
        try {
            $page->call('disconnect', 'google');
            $this->fail('Another agent\'s calendar must not be found');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1); // a 404 in a real request
        }
        $this->assertSame(1, AgentCalendarConnection::query()->count());
    }
}
