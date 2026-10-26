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
use Laravel\Sanctum\Sanctum;
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

    public function test_admins_pick_the_website_colour_and_whether_visitors_may_change_it(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Appearance::class)->assertSet('siteDefault', 'indigo')->assertSet('sitePicker', true)
            ->set('siteDefault', 'neon')->call('saveSite')->assertHasErrors('siteDefault')
            ->set('siteDefault', 'ocean')->call('saveSite')->assertHasNoErrors();
        $this->app['auth']->forgetGuards();

        // Every visitor starts on Ocean, drawn by the server; their own choice still wins in their browser.
        $this->app->forgetScopedInstances();
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="en" data-site-theme="ocean" data-site-theme-default="ocean">', $html);
        $this->assertStringContainsString("localStorage.getItem('surehelp.site-theme')", $html);
        $this->assertStringContainsString('id="site-theme-picker"', $html);

        // With the picker hidden, nobody's stored choice is read and the palette button is gone.
        $this->actingAs($this->admin);
        Livewire::test(Appearance::class)->set('sitePicker', false)->call('saveSite')->assertHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertStringContainsString('data-site-theme="ocean"', $html);
        $this->assertStringNotContainsString("localStorage.getItem('surehelp.site-theme')", $html);
        $this->assertStringNotContainsString('id="site-theme-picker"', $html);

        $log = AuditLog::query()->where('action', 'ui.site_theme_changed')->latest('id')->first();
        $this->assertSame(['theme' => 'ocean', 'visitor_picker' => true], $log->old_values);
        $this->assertSame(['theme' => 'ocean', 'visitor_picker' => false], $log->new_values);

        // The base palette needs no attribute.
        app(Themes::class)->setSite('indigo', true);
        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('<html lang="en" data-site-theme-default="indigo">', $this->get(route('home'))->getContent());
    }

    public function test_the_mobile_app_reads_and_saves_the_same_theme_as_the_web(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'password' => 'Secret123!']);
        app(ProvisionUserTenancy::class)->handle($owner);
        $owner->forceFill(['theme' => 'ocean'])->save();

        // Login and GET /user carry the theme to show.
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'Secret123!'])->assertOk()
            ->assertJsonPath('data.theme', ['key' => 'ocean', 'own' => true, 'default' => 'midnight']);

        Sanctum::actingAs($owner->fresh());
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.theme.key', 'ocean');

        // GET /themes: every theme with its colours; "system" says what it resolves to.
        $themes = collect($this->getJson('/api/v1/themes')->assertOk()->assertJsonPath('data.current.key', 'ocean')->json('data.themes'))->keyBy('key');
        $this->assertSame(array_keys(config('themes.portal')), $themes->keys()->all());
        $this->assertSame('#ffffff', $themes['light']['colors']['surface']);
        $this->assertSame('light', $themes['light']['mode']);
        $this->assertSame(['light' => 'light', 'dark' => 'midnight'], $themes['system']['resolves_to']);
        $this->assertArrayNotHasKey('colors', $themes['system']);

        // PUT /user/theme saves to the account: the web shows it too.
        $this->putJson('/api/v1/user/theme', ['theme' => 'sunset'])->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.theme', ['key' => 'sunset', 'own' => true, 'default' => 'midnight']);
        $this->assertSame('sunset', $owner->fresh()->theme);
        $this->putJson('/api/v1/user/theme', ['theme' => 'neon'])->assertUnprocessable()->assertJsonPath('success', false)->assertJsonStructure(['errors' => ['theme']]);
        $this->putJson('/api/v1/user/theme', ['theme' => 'default'])->assertOk()->assertJsonPath('data.theme.own', false);
        $this->assertNull($owner->fresh()->theme);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/themes')->assertUnauthorized();
    }

    public function test_the_app_colours_match_the_website_css(): void
    {
        $tokens = ['canvas' => 'canvas', 'surface' => 'surface', 'surface_2' => 'surface-2', 'surface_3' => 'surface-3', 'ink' => 'ink',
            'muted' => 'muted', 'subtle' => 'subtle', 'brand' => 'brand-400', 'brand_strong' => 'brand-600', 'accent' => 'accent-500',
            'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'info' => 'info'];
        $read = function (string $css): array {
            preg_match_all('/--color-([a-z0-9-]+):\s*([^;]+);/', $css, $m);

            return array_combine($m[1], array_map(fn ($v) => strtolower(trim($v)), $m[2]));
        };
        preg_match('/@theme\s*\{(.*?)\n\}/s', file_get_contents(resource_path('css/app.css')), $base);
        $midnight = $read($base[1]);
        $css = file_get_contents(resource_path('css/themes.css'));

        foreach (config('themes.portal') as $key => $theme) {
            if ($key === 'system') {
                continue;
            }
            $values = $midnight;
            if ($key !== 'midnight') {
                preg_match("/:root\[data-theme='{$key}'\]\s*\{(.*?)\}/s", $css, $block);
                $values = array_merge($midnight, $read($block[1]));
            }
            foreach ($tokens as $app => $token) {
                $this->assertSame($values[$token], strtolower($theme['colors'][$app]), "{$key}.{$app} must match --color-{$token}");
            }
        }
    }
}
