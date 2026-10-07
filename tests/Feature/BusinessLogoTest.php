<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Business\Profile;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Business logo (spec §9): owners upload and remove it; it's served publicly by the business's ULID.
 */
class BusinessLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_uploads_replaces_and_removes_the_logo(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $this->actingAs($owner);

        Livewire::test(Profile::class)->set('logo', UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))->assertHasErrors('logo');
        Livewire::test(Profile::class)->set('logo', UploadedFile::fake()->image('logo.png', 200, 100))->assertHasNoErrors();
        $first = BusinessProfile::query()->sole()->logo_path;
        Storage::disk('local')->assertExists($first);

        Livewire::test(Profile::class)->set('logo', UploadedFile::fake()->image('new.png', 200, 100));
        $profile = BusinessProfile::query()->sole();
        Storage::disk('local')->assertMissing($first);
        $this->get(route('app.dashboard'))->assertSee($profile->logoUrl(), false);

        auth()->logout();
        $this->get($profile->logoUrl())->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        $this->actingAs($owner);
        Livewire::test(Profile::class)->call('removeLogo');
        $this->assertNull($profile->fresh()->logo_path);
        $this->get(route('business.logo', ['business' => $org->ulid]))->assertNotFound();
    }
}
