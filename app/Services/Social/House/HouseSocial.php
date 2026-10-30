<?php

namespace App\Services\Social\House;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * SureHelp's own social media (D104). SureHelp is an internal organization, so connecting accounts,
 * the media library, the post editor and publishing all reuse what businesses use. The schedule below
 * says when the AI prepares posts and what kind.
 */
class HouseSocial
{
    public const SETTING = 'house_social.schedule';

    public const LAST_RUN = 'house_social.last_run';

    public const KINDS = ['text' => 'Text only', 'image' => 'Picture and text', 'video' => 'Video and text'];

    public const PICTURES = [
        'library' => 'Only from the library',
        'when_needed' => 'From the library; ChatGPT makes one when every picture was used in the last 30 days',
        'always' => 'ChatGPT makes a new picture every time',
    ];

    /** The internal organization that owns SureHelp's accounts, posts and media (created on first use). */
    public function organization(): Organization
    {
        $existing = Organization::query()->where('is_internal', true)->first();
        if ($existing) {
            return $existing;
        }

        $organization = new Organization(['name' => 'SureHelp', 'status' => OrganizationStatus::Active, 'timezone' => $this->settings()['timezone']]);
        $organization->forceFill(['is_internal' => true])->save();

        return $organization;
    }

    /**
     * @return array{enabled: bool, timezone: string, times: list<string>, days: list<int>, accounts: list<string>,
     *   mix: array{text: int, image: int, video: int}, pictures: string, about: string, topics: string, voice: string,
     *   link: string, approval: bool, author_id: ?int}
     */
    public function settings(): array
    {
        $saved = (array) (rescue(fn () => PlatformSetting::get(self::SETTING), null, report: false) ?? []);

        return array_replace([
            'enabled' => false,
            'timezone' => 'America/New_York',
            'times' => ['09:00', '13:00', '18:00'],
            'days' => [1, 2, 3, 4, 5, 6, 7],
            'accounts' => [],     // empty: every connected account
            'mix' => ['text' => 20, 'image' => 50, 'video' => 30],
            'pictures' => 'when_needed',
            'about' => 'SureHelp gives small US service businesses live receptionists who answer every call 24/7, book appointments and follow up, plus simple software for the calendar, customers, messages and growth.',
            'topics' => "Missed calls cost jobs\nWhat our receptionists do on a call\nTips for small business owners\nA feature of the SureHelp portal\nCustomer service ideas",
            'voice' => 'Friendly, plain and confident. Short sentences. No jargon, no hype.',
            'link' => rtrim((string) config('app.url'), '/'),
            'approval' => false,
            'author_id' => null,
        ], $saved);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function save(array $input, User $by): array
    {
        $times = collect((array) ($input['times'] ?? []))->map(fn ($t) => trim((string) $t))->filter()
            ->each(function (string $t) {
                if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                    throw ValidationException::withMessages(['times' => "\"{$t}\" isn't a time like 09:00 or 18:30."]);
                }
            })->unique()->sort()->values()->all();
        $mix = array_map(fn ($v) => max(0, min(100, (int) $v)), array_intersect_key((array) ($input['mix'] ?? []), self::KINDS) + ['text' => 0, 'image' => 0, 'video' => 0]);
        $enabled = (bool) ($input['enabled'] ?? false);

        if ($enabled && $times === []) {
            throw ValidationException::withMessages(['times' => 'Add at least one time to post.']);
        }
        if (count($times) > 12) {
            throw ValidationException::withMessages(['times' => 'Up to 12 posts a day.']);
        }
        if (array_sum($mix) === 0) {
            throw ValidationException::withMessages(['mix' => 'Choose at least one kind of post.']);
        }
        if (! in_array($input['timezone'] ?? '', timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['timezone' => 'Choose a time zone.']);
        }

        $settings = array_replace($this->settings(), [
            'enabled' => $enabled,
            'timezone' => (string) $input['timezone'],
            'times' => $times,
            'days' => collect((array) ($input['days'] ?? []))->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 7)->unique()->sort()->values()->all() ?: [1, 2, 3, 4, 5, 6, 7],
            'accounts' => array_values(array_filter(array_map('strval', (array) ($input['accounts'] ?? [])))),
            'mix' => $mix,
            'pictures' => array_key_exists($input['pictures'] ?? '', self::PICTURES) ? $input['pictures'] : 'when_needed',
            'about' => mb_substr(trim((string) ($input['about'] ?? '')), 0, 2000),
            'topics' => mb_substr(trim((string) ($input['topics'] ?? '')), 0, 4000),
            'voice' => mb_substr(trim((string) ($input['voice'] ?? '')), 0, 1000),
            'link' => mb_substr(trim((string) ($input['link'] ?? '')), 0, 300),
            'approval' => (bool) ($input['approval'] ?? false),
            'author_id' => $by->id,
        ]);
        PlatformSetting::put(self::SETTING, $settings);
        $this->organization()->forceFill(['timezone' => $settings['timezone']])->save();
        app(Audit::class)->record('house_social.schedule_saved', null, new: array_diff_key($settings, ['about' => 1, 'topics' => 1, 'voice' => 1]), actor: $by, label: 'SureHelp social schedule');

        return $settings;
    }

    /** The person posts are written as: whoever last saved the schedule, else the first active admin. */
    public function author(): ?User
    {
        $id = $this->settings()['author_id'];

        return ($id ? User::query()->where('is_active', true)->find($id) : null)
            ?? User::query()->where('role', 'admin')->where('is_active', true)->orderBy('id')->first();
    }
}
