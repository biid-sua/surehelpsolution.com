<?php

namespace Tests\Feature;

use App\Models\BusinessHoliday;
use App\Models\BusinessHour;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Services\Business\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The opening-hours engine (spec §10, §74). Times are local to the business unless stated.
 */
class BusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $timezone = 'America/New_York'): Organization
    {
        return Organization::factory()->create(['timezone' => $timezone]);
    }

    /** @param array<int, list<array{0: string, 1: string}>> $week day => [[opens, closes], …] */
    private function hours(Organization $org, array $week): void
    {
        foreach ($week as $day => $intervals) {
            foreach ($intervals as [$opens, $closes]) {
                BusinessHour::create(['organization_id' => $org->id, 'day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes]);
            }
        }
    }

    private function at(Organization $org, string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $org->timezone);
    }

    private function engine(): BusinessHours
    {
        return new BusinessHours; // fresh instance: no cached hours between assertions
    }

    public function test_regular_hours_with_exclusive_closing_time(): void
    {
        $org = $this->business();
        $this->hours($org, [1 => [['09:00', '17:00']]]); // Monday

        $this->assertTrue($this->engine()->isOpenAt($org, $this->at($org, '2026-10-05 09:00')));
        $this->assertTrue($this->engine()->isOpenAt($org, $this->at($org, '2026-10-05 16:59')));
        $this->assertFalse($this->engine()->isOpenAt($org, $this->at($org, '2026-10-05 17:00')));
        $this->assertSame('Open until 5 PM', $this->engine()->status($org, $this->at($org, '2026-10-05 10:00'))['label']);
    }

    public function test_split_shift_lunch_break(): void
    {
        $org = $this->business();
        $this->hours($org, [2 => [['08:00', '12:00'], ['13:30', '17:00']]]); // Tuesday

        $status = $this->engine()->status($org, $this->at($org, '2026-10-06 12:30'));

        $this->assertFalse($status['open']);
        $this->assertSame('Closed · opens at 1:30 PM', $status['label']);
        $this->assertTrue($this->engine()->isOpenAt($org, $this->at($org, '2026-10-06 14:00')));
    }

    public function test_closed_days_are_skipped_when_finding_the_next_opening(): void
    {
        $org = $this->business();
        $this->hours($org, [1 => [['09:00', '17:00']], 5 => [['09:00', '17:00']]]); // Mon + Fri only

        $status = $this->engine()->status($org, $this->at($org, '2026-10-09 18:00')); // Friday evening

        $this->assertSame('2026-10-12 09:00', $status['next_open']->format('Y-m-d H:i')); // Monday
        $this->assertSame('Closed · opens Monday at 9 AM', $status['label']);
    }

    public function test_overnight_hours_continue_past_midnight(): void
    {
        $org = $this->business();
        $this->hours($org, [5 => [['22:00', '02:00']]]); // Friday night

        $this->assertTrue($this->engine()->isOpenAt($org, $this->at($org, '2026-10-09 23:00')));
        $this->assertTrue($this->engine()->isOpenAt($org, $this->at($org, '2026-10-10 01:30'))); // Saturday
        $this->assertFalse($this->engine()->isOpenAt($org, $this->at($org, '2026-10-10 02:00')));
    }

    public function test_holiday_closes_and_special_hours_replace_the_day(): void
    {
        $org = $this->business();
        $this->hours($org, [4 => [['09:00', '17:00']], 5 => [['09:00', '17:00']]]);
        BusinessHoliday::create(['organization_id' => $org->id, 'date' => '2026-11-26', 'name' => 'Thanksgiving', 'is_closed' => true]);
        BusinessHoliday::create(['organization_id' => $org->id, 'date' => '2026-11-27', 'name' => 'Black Friday', 'is_closed' => false, 'opens_at' => '10:00', 'closes_at' => '14:00']);

        $thanksgiving = $this->engine()->status($org, $this->at($org, '2026-11-26 11:00'));
        $this->assertFalse($thanksgiving['open']);
        $this->assertSame('holiday', $thanksgiving['reason']);
        $this->assertStringStartsWith('Thanksgiving · Closed · opens tomorrow at 10 AM', $thanksgiving['label']);

        $this->assertFalse($this->engine()->isOpenAt($org, $this->at($org, '2026-11-27 09:30')));
        $this->assertTrue($this->engine()->isOpenAt($org, $this->at($org, '2026-11-27 13:00')));
    }

    public function test_temporary_closure(): void
    {
        $org = $this->business();
        $this->hours($org, collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => [['09:00', '17:00']]])->all());
        BusinessProfile::create(['organization_id' => $org->id, 'closed_until' => '2026-10-07', 'closure_message' => 'On vacation']);

        $status = $this->engine()->status($org, $this->at($org, '2026-10-06 10:00'));

        $this->assertFalse($status['open']);
        $this->assertSame('temporarily_closed', $status['reason']);
        $this->assertSame('2026-10-08 09:00', $status['next_open']->format('Y-m-d H:i'));
    }

    public function test_business_timezone_not_server_timezone(): void
    {
        $ny = $this->business('America/New_York');
        $la = $this->business('America/Los_Angeles');
        $this->hours($ny, [1 => [['09:00', '17:00']]]);
        $this->hours($la, [1 => [['09:00', '17:00']]]);

        $instant = CarbonImmutable::parse('2026-10-05 14:00', 'UTC'); // 10:00 New York, 07:00 Los Angeles

        $this->assertTrue($this->engine()->isOpenAt($ny, $instant));
        $this->assertFalse($this->engine()->isOpenAt($la, $instant));
    }

    public function test_daylight_saving_change_is_respected(): void
    {
        $org = $this->business('America/New_York');
        $this->hours($org, [0 => [['01:00', '05:00']]]); // Sunday 2026-03-08: clocks jump 02:00 → 03:00

        // 01:30 EST = 06:30 UTC → open. 05:30 EDT = 09:30 UTC → closed.
        $this->assertTrue($this->engine()->isOpenAt($org, CarbonImmutable::parse('2026-03-08 06:30', 'UTC')));
        $this->assertFalse($this->engine()->isOpenAt($org, CarbonImmutable::parse('2026-03-08 09:30', 'UTC')));

        $interval = $this->engine()->intervalsOn($org, CarbonImmutable::parse('2026-03-08'))[0];
        $this->assertSame(3, (int) $interval['start']->diffInHours($interval['end']), 'the 4-hour shift is 3 real hours on DST day');
    }

    public function test_no_hours_configured(): void
    {
        $org = $this->business();

        $status = $this->engine()->status($org, $this->at($org, '2026-10-05 10:00'));

        $this->assertFalse($status['open']);
        $this->assertNull($status['next_open']);
        $this->assertSame('Closed', $status['label']);
    }

    public function test_weekly_display_is_monday_first_and_formatted(): void
    {
        $org = $this->business();
        $this->hours($org, [1 => [['08:00', '12:00'], ['13:00', '17:30']], 0 => []]);

        $weekly = $this->engine()->weekly($org);

        $this->assertSame('Monday', array_key_first($weekly));
        $this->assertSame(['8 AM – 12 PM', '1 PM – 5:30 PM'], $weekly['Monday']);
        $this->assertSame([], $weekly['Sunday']);
    }
}
