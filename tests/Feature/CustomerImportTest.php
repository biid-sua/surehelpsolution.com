<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Client\Customers\Import;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D53: customers imported from a CSV, with column matching, existing customers matched by phone
 * or email, row problems reported, consent never imported.
 */
class CustomerImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('customers.csv', $content);
    }

    public function test_an_owner_imports_a_spreadsheet(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $existing = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'phone' => '(512) 555-0101']);

        $file = $this->csv("\xEF\xBB\xBFCustomer Name,Mobile,E-mail,City,Tags,Opted in\n"
            ."Bob Stone,512-555-0199,bob@example.test,Austin,\"vip, repeat\",yes\n"
            ."Ana Lopez,(512) 555-0101,ana@example.test,Round Rock,,yes\n"
            .",,,,,\n"
            ."Cara,not-a-phone,,,,\n"
            ."Dee,,dee@example.test,,,\n");

        $this->actingAs($owner)->get(route('app.customers.import'))->assertOk();
        $page = Livewire::test(Import::class)->set('file', $file)->assertHasNoErrors()
            ->assertSet('rowCount', 4)
            ->assertSet('mapping', [0 => 'name', 1 => 'phone', 2 => 'email', 3 => 'city', 4 => 'tags', 5 => ''])
            ->set('fillExisting', true)
            ->call('import');

        $this->assertSame(['created' => 2, 'updated' => 1, 'skipped' => 1], array_intersect_key($page->get('result'), array_flip(['created', 'updated', 'skipped'])));
        $this->assertStringContainsString('Row 5', $page->get('result')['problems'][0]);

        $bob = Customer::query()->where('email', 'bob@example.test')->sole();
        $this->assertSame(['Bob', 'Stone', '+15125550199', 'import'], [$bob->first_name, $bob->last_name, $bob->phone_e164, $bob->source]);
        $this->assertFalse((bool) $bob->sms_consent, 'consent is never imported');
        $this->assertEqualsCanonicalizing(['vip', 'repeat'], $bob->tags->pluck('name')->all());
        $existing->refresh();
        $this->assertSame('ana@example.test', $existing->email, 'empty fields filled');
        $this->assertSame('Ana', $existing->first_name);
        $this->assertSame(0, AutomationRun::query()->count(), 'imports don\'t trigger "new customer" automations');
    }

    public function test_bad_files_and_mappings_are_explained(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        $this->actingAs($owner);

        Livewire::test(Import::class)->set('file', $this->csv("Name\n"))->assertHasErrors('file');
        Livewire::test(Import::class)->set('file', $this->csv("Colour,Size\nred,L\n"))->assertHasNoErrors()
            ->call('import')->assertHasErrors('mapping')
            ->set('mapping', [0 => 'phone', 1 => 'phone'])->call('import')->assertHasErrors('mapping');
    }
}
