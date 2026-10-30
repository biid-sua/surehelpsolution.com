<?php

namespace App\Livewire\Admin\Social;

use App\Enums\SocialPostStatus;
use App\Exceptions\AiUnavailable;
use App\Livewire\Concerns\HouseSocialAdmin;
use App\Livewire\Concerns\SocialPortal;
use App\Models\PlatformSetting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Ai\AiImages;
use App\Services\Ai\AiProviders;
use App\Services\Social\House\HouseAutopilot;
use App\Services\Social\House\HouseSocial;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * SureHelp's daily posting schedule (D104): the times to post, which accounts, the mix of text,
 * picture and video posts, and what the AI should write about. Posts are prepared a day ahead and
 * can be edited, cancelled or (with the switch on) approved first under Posts.
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('SureHelp posting schedule')]
class Schedule extends Component
{
    use HouseSocialAdmin, SocialPortal {
        HouseSocialAdmin::socialPortal insteadof SocialPortal;
    }

    /** @var array<string, mixed> */
    public array $form = [];

    public string $newTime = '';

    public function mount(HouseSocial $house): void
    {
        $this->form = $house->settings();
        $this->form['days'] = array_map('strval', $this->form['days']);
    }

    public function addTime(): void
    {
        $this->authorizeManage();
        $this->resetErrorBag('newTime');
        $time = trim($this->newTime);
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $this->addError('newTime', 'Choose a time.');

            return;
        }
        $this->form['times'] = collect($this->form['times'])->push($time)->unique()->sort()->values()->all();
        $this->newTime = '';
    }

    public function removeTime(string $time): void
    {
        $this->authorizeManage();
        $this->form['times'] = array_values(array_diff($this->form['times'], [$time]));
    }

    public function save(HouseSocial $house): void
    {
        $this->authorizeManage();
        try {
            $this->form = $house->save($this->form, auth()->user());
            $this->form['days'] = array_map('strval', $this->form['days']);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }

            return;
        }

        $this->dispatch('toast', type: 'success', message: $this->form['enabled'] ? 'Schedule saved. Posts are prepared a day ahead.' : 'Schedule saved. Automatic posting is off.');
    }

    /** Writes the post for the next free time now, so the result can be checked straight away. */
    public function prepareNext(HouseAutopilot $autopilot): void
    {
        $this->authorizeManage();
        if (RateLimiter::tooManyAttempts($key = 'house-social-prepare:'.auth()->id(), 10)) {
            $this->dispatch('toast', type: 'warning', message: 'Please wait a few minutes before preparing more posts.');

            return;
        }
        RateLimiter::hit($key, 600);

        $slot = $autopilot->nextFreeSlot();
        if (! $slot) {
            $this->dispatch('toast', type: 'warning', message: 'Every time in the next week already has a post. Add a time, or cancel a post first.');

            return;
        }

        try {
            $post = $autopilot->prepare($slot);
        } catch (RuntimeException|ValidationException|AiUnavailable $e) {
            $this->dispatch('toast', type: 'error', message: $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: $post->status === SocialPostStatus::InReview
            ? 'Post ready for '.$slot->format('D j M, g:i A').'. It waits for approval under Posts.'
            : 'Post scheduled for '.$slot->format('D j M, g:i A').'.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasPermissionIn('social.manage'), 403);
    }

    public function render(HouseSocial $house, HouseAutopilot $autopilot, AiProviders $providers, AiImages $images): View
    {
        $organization = $house->organization();
        $timezone = in_array($this->form['timezone'] ?? '', timezone_identifiers_list(), true) ? $this->form['timezone'] : 'America/New_York';
        $posts = SocialPost::query()->forOrganization($organization)->whereNotNull('autopilot_slot')
            ->where('scheduled_at', '>=', now()->subDay())->orderBy('scheduled_at')->limit(30)->get()->keyBy('autopilot_slot');

        // The next 7 days of times, with the post prepared for each (if any).
        $upcoming = collect($autopilot->dueSlots(CarbonImmutable::now(), 24 * 7))->take(21)
            ->map(fn (CarbonImmutable $slot) => ['at' => $slot, 'post' => $posts->get(HouseAutopilot::key($slot))]);

        return view('livewire.admin.social.schedule', [
            'accounts' => SocialAccount::query()->forOrganization($organization)->usable()->orderBy('network')->get(),
            'upcoming' => $upcoming,
            'lastRun' => (array) (rescue(fn () => PlatformSetting::get(HouseSocial::LAST_RUN), null, report: false) ?? []),
            'writer' => $providers->label(config('ai.social_provider') ?: null),
            'picturesReady' => $images->available(),
            'timezoneLabel' => $timezone,
            'canManage' => (bool) auth()->user()?->hasPermissionIn('social.manage'),
        ]);
    }
}
