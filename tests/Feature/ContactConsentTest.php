<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public contact form records text-message consent only when the visitor gives it (spec §57).
 */
class ContactConsentTest extends TestCase
{
    use RefreshDatabase;

    private function submit(array $extra = []): void
    {
        $this->post(route('contact.store'), $extra + [
            'name' => 'Ana Lopez', 'email' => 'ana@example.test', 'phone' => '512 555 0147',
            'inquiry_type' => array_key_first(ContactSubmission::INQUIRY_TYPES), 'message' => 'Tell me about pricing please.', 'privacy' => '1',
        ])->assertRedirect();
    }

    public function test_text_message_consent_is_optional_and_recorded_as_given(): void
    {
        Mail::fake();

        $this->submit();
        $this->assertFalse((bool) ContactSubmission::latest('id')->first()->sms_consent);

        $this->submit(['sms_consent' => '1']);
        $this->assertTrue((bool) ContactSubmission::latest('id')->first()->sms_consent);

        $this->post(route('contact.store'), ['name' => 'X', 'email' => 'x@example.test', 'inquiry_type' => array_key_first(ContactSubmission::INQUIRY_TYPES), 'message' => 'Hello there friend'])
            ->assertSessionHasErrors('privacy');
        $this->assertSame(2, ContactSubmission::count());

        $this->get(route('home'))->assertSee('contact_sms_consent', false)->assertSee('(Optional) I agree to receive communications by text message', false);
    }
}
