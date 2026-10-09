<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public website (D55): every page works, every link goes somewhere, nothing loads from a
 * CDN, and no claim is shown that SureHelp can't back up.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function pages(): array
    {
        return array_merge(
            [route('home'), route('site.how'), route('site.pricing'), route('site.about'), route('site.contact'), route('site.industries.index'), route('legal.faq')],
            array_map(fn ($s) => route('site.services.show', $s), array_keys(config('marketing.services'))),
            array_map(fn ($i) => route('site.industries.show', $i), array_keys(config('marketing.industries'))),
            array_map(fn ($p) => route('legal.'.$p), ['privacy-policy', 'terms-of-use', 'data-security', 'cookie-notice', 'data-processing-addendum', 'business-associate-agreement']),
        );
    }

    public function test_every_page_renders_without_dead_links_cdns_or_unsupported_claims(): void
    {
        foreach ($this->pages() as $url) {
            $html = $this->get($url)->assertOk()->assertSee('<title>', false)->assertSee('rel="canonical"', false)->getContent();

            $this->assertDoesNotMatchRegularExpression('~href="#"~', $html, "{$url} has a dead link");
            $this->assertDoesNotMatchRegularExpression('~(cdn\.jsdelivr|unpkg\.com|cdnjs\.cloudflare|fonts\.googleapis|font-awesome)~', $html, "{$url} loads from a CDN");
            foreach (['500+', 'thousands of businesses', 'SOC 2', 'Bank-level', '99.9%', 'Mike Johnson', 'Netflix'] as $claim) {
                $this->assertStringNotContainsString($claim, $html, "{$url} shows an unsupported claim: {$claim}");
            }
        }
    }

    public function test_menus_link_to_every_service_industry_and_page(): void
    {
        $html = $this->get(route('home'))->getContent();
        foreach (array_keys(config('marketing.services')) as $service) {
            $this->assertStringContainsString(route('site.services.show', $service), $html);
        }
        foreach (array_keys(config('marketing.industries')) as $industry) {
            $this->assertStringContainsString(route('site.industries.show', $industry), $html);
        }
        foreach (['site.pricing', 'site.about', 'site.contact', 'login', 'legal.privacy-policy', 'legal.terms-of-use'] as $route) {
            $this->assertStringContainsString(route($route), $html);
        }

        // Signed in: the header offers the way back to the portal instead of "Sign in".
        $client = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $this->actingAs($client)->get(route('home'))->assertSee('Open your portal');
    }

    public function test_the_contact_page_preselects_the_reason_and_returns_to_the_form(): void
    {
        Mail::fake();
        $this->get(route('site.contact', ['topic' => 'demo']))->assertOk()->assertSee('Book a demo')->assertSee('value="demo" selected', false);

        $this->from(route('site.contact', ['topic' => 'demo']))->post(route('contact.store'), [
            'name' => 'Ana Lopez', 'email' => 'ana@example.test', 'inquiry_type' => 'demo', 'message' => 'Could we see it next week?', 'privacy' => '1',
        ])->assertRedirect(route('site.contact', ['topic' => 'demo']).'#contact-form')->assertSessionHas('contact_success');
        $this->assertSame('demo', ContactSubmission::sole()->inquiry_type);

        // Errors also come back to the form, not the top of the page.
        $this->from(route('home'))->post(route('contact.store'), ['name' => '', 'email' => 'x'])
            ->assertRedirect(route('home').'#contact-form')->assertSessionHasErrors(['name', 'email', 'message', 'privacy']);
    }

    public function test_bots_filling_the_hidden_field_are_ignored(): void
    {
        Mail::fake();
        $this->post(route('contact.store'), [
            'name' => 'Bot', 'email' => 'bot@example.test', 'inquiry_type' => 'general', 'message' => 'Buy cheap things now', 'privacy' => '1', 'website' => 'http://spam.test',
        ])->assertRedirect()->assertSessionHas('contact_success');
        $this->assertSame(0, ContactSubmission::count());
        Mail::assertNothingSent();
    }

    public function test_search_engines_get_a_sitemap_and_errors_are_branded(): void
    {
        $this->get(route('site.sitemap'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.route('site.pricing').'</loc>', false)
            ->assertSee('<loc>'.route('site.services.show', 'call-answering').'</loc>', false);

        $this->get('/services/not-a-service')->assertNotFound()->assertSee('We couldn\'t find that page', false);
        $this->get('/industries/not-an-industry')->assertNotFound();
    }
}
