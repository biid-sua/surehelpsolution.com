<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Appearance;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ThemeReset;
use App\Support\Ui\Themes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Colour themes (D59): each person's own choice is stored on their account; administrators set the
 * default for everyone else and can only override a personal choice through an explicit reset.
 */
class ThemesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);

        return $owner->fresh();
    }

    private function themeOn(string $url): string
    {
        preg_match('/<html[^>]*data-theme="([a-z]+)"[^>]*data-theme-choice="([a-z]+)"/', $this->get($url)->assertOk()->getContent(), $m);

        return ($m[1] ?? '?').'/'.($m[2] ?? '?');
    }

    public function test_everyone_sees_midnight_until_they_choose_and_their_choice_is_kept(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $this->assertSame('midnight/midnight', $this->themeOn(route('app.dashboard')));

        $this->putJson(route('account.theme'), ['theme' => 'light'])->assertOk()->assertJson(['theme' => 'light', 'own' => true]);
        $this->assertSame('light', $owner->fresh()->theme);
        $this->assertNotNull($owner->fresh()->theme_chosen_at);
        $this->assertSame('light/light', $this->themeOn(route('app.dashboard')));
        $this->assertSame('light/light', $this->themeOn(route('account.profile')));

        // "Match my device" is drawn as Midnight and switched on the device before paint.
        $this->putJson(route('account.theme'), ['theme' => 'system'])->assertOk();
        $this->assertSame('midnight/system', $this->themeOn(route('app.dashboard')));

        $this->putJson(route('account.theme'), ['theme' => 'neon'])->assertUnprocessable();
        $this->putJson(route('account.theme'), ['theme' => 'default'])->assertOk()->assertJson(['own' => false]);
        $this->assertNull($owner->fresh()->theme);
    }

    public function test_the_admin_default_applies_to_everyone_who_has_not_chosen(): void
    {
        $chooser = $this->owner();
        $chooser->forceFill(['theme' => 'forest'])->save();
        $follower = $this->owner();

        $this->actingAs($this->admin);
        Livewire::test(Appearance::class)->set('default', 'ocean')->call('saveDefault')->assertHasNoErrors()->assertDispatched('toast');
        $this->assertSame('ocean', app(Themes::class)->default());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ui.default_theme_changed']);

        app()->forgetScopedInstances();
        $this->actingAs($follower);
        $this->assertSame('ocean/ocean', $this->themeOn(route('app.dashboard')));
        app()->forgetScopedInstances();
        $this->actingAs($chooser);
        $this->assertSame('forest/forest', $this->themeOn(route('app.dashboard')), 'a personal choice survives a new default');
        $this->assertSame('forest', $chooser->fresh()->theme);

        // Sign-in pages use the default too.
        auth()->logout();
        app()->forgetScopedInstances();
        $this->assertSame('ocean/ocean', $this->themeOn(route('login')));
    }

    public function test_resetting_personal_choices_needs_a_reason_and_confirmation_and_tells_people(): void
    {
        $a = $this->owner();
        $a->forceFill(['theme' => 'sunset'])->save();
        $b = $this->owner();
        $b->forceFill(['theme' => 'light'])->save();

        $this->actingAs($this->admin);
        $page = Livewire::test(Appearance::class)->set('resetScope', 'one')->set('resetEmail', $a->email);
        $page->call('resetChoices')->assertHasErrors(['resetReason', 'resetConfirm']);
        $page->set('resetReason', 'They asked us to reset it')->set('resetConfirm', 'reset')->call('resetChoices')->assertHasErrors(['resetConfirm']);
        $this->assertSame('sunset', $a->fresh()->theme, 'nothing changes without the typed confirmation');

        $page->set('resetConfirm', 'RESET')->call('resetChoices')->assertHasNoErrors();
        $this->assertNull($a->fresh()->theme);
        $this->assertSame('light', $b->fresh()->theme, 'only the person named');
        Notification::assertSentTo($a, ThemeReset::class, fn (ThemeReset $n) => $n->reason === 'They asked us to reset it');
        Notification::assertNotSentTo($b, ThemeReset::class);
        $log = AuditLog::query()->where('action', 'ui.themes_reset')->sole();
        $this->assertSame(1, $log->new_values['people']);

        Livewire::test(Appearance::class)->set('resetScope', 'everyone')->set('resetReason', 'Light theme fix needs a fresh start')
            ->set('resetConfirm', 'RESET')->call('resetChoices')->assertHasNoErrors();
        $this->assertSame(0, User::query()->whereNotNull('theme')->count());
    }

    public function test_only_administrators_with_settings_access_change_the_default(): void
    {
        $operations = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $operations->syncRoles(['operations_manager']);

        $this->actingAs($operations)->get(route('admin.appearance'))->assertOk();
        Livewire::test(Appearance::class)->set('default', 'light')->call('saveDefault')->assertForbidden();
        Livewire::test(Appearance::class)->set('resetScope', 'everyone')->set('resetReason', 'No reason at all here')
            ->set('resetConfirm', 'RESET')->call('resetChoices')->assertForbidden();

        $this->actingAs($this->owner())->get(route('admin.appearance'))->assertForbidden();
    }

    public function test_every_theme_has_its_colours(): void
    {
        $portal = file_get_contents(resource_path('css/themes.css'));
        foreach (array_keys(config('themes.portal')) as $key) {
            if (! in_array($key, ['midnight', 'system'], true)) {
                $this->assertStringContainsString("[data-theme='{$key}']", $portal, "portal theme {$key}");
            }
        }
        $this->assertContains(config('themes.default'), array_keys(config('themes.portal')));

        $site = file_get_contents(public_path('assets/css/site-themes.css'));
        foreach (array_keys(config('themes.site')) as $key) {
            if ($key !== 'indigo') {
                $this->assertStringContainsString("[data-site-theme='{$key}']", $site, "site theme {$key}");
            }
        }
    }

    public function test_the_public_site_offers_its_own_themes_kept_in_the_browser(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.getItem('surehelp.site-theme')", $html);
        $this->assertStringContainsString('assets/css/site-themes.css', $html);
        foreach (array_keys(config('themes.site')) as $key) {
            $this->assertStringContainsString('data-site-theme-option="'.$key.'"', $html);
        }
        // Nothing about the visitor's site theme is stored on the server.
        $this->assertStringNotContainsString(route('account.theme'), $html);
    }
}
