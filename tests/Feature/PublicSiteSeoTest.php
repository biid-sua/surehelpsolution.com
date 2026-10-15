<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Search engines see the public website at its best and never the private areas (D58).
 */
class PublicSiteSeoTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> the @graph entities in the page's JSON-LD */
    private function schema(TestResponse $response): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $blocks);
        $this->assertNotEmpty($blocks[1], 'the page has structured data');

        return collect($blocks[1])->flatMap(function (string $json) {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('https://schema.org', $data['@context']);

            return $data['@graph'];
        })->all();
    }

    /** @return array<string, string> */
    private function headTags(TestResponse $response): array
    {
        $html = $response->getContent();
        preg_match('#<title>(.*?)</title>#s', $html, $title);
        preg_match('#<meta name="description" content="(.*?)">#s', $html, $description);
        preg_match('#<link rel="canonical" href="(.*?)">#s', $html, $canonical);
        preg_match('#<meta property="og:image" content="(.*?)">#s', $html, $image);

        return ['title' => html_entity_decode($title[1] ?? ''), 'description' => html_entity_decode($description[1] ?? ''),
            'canonical' => $canonical[1] ?? '', 'image' => $image[1] ?? ''];
    }

    public function test_every_public_page_has_a_distinct_title_description_and_canonical(): void
    {
        $urls = array_merge([route('home'), route('legal.faq')], array_map(fn ($slug) => route('pages.show', $slug), array_keys(config('pages'))));
        $titles = [];
        foreach ($urls as $url) {
            $response = $this->get($url.'?utm_source=newsletter')->assertOk();
            $head = $this->headTags($response);

            $this->assertNotSame('', $head['title'], $url);
            $this->assertLessThanOrEqual(65, mb_strlen($head['title']), "$url title fits in search results");
            $this->assertGreaterThanOrEqual(100, mb_strlen($head['description']), "$url description");
            $this->assertLessThanOrEqual(165, mb_strlen($head['description']), "$url description");
            $this->assertStringNotContainsString('&amp;', $head['title'], 'escaped once, not twice');
            $this->assertSame($url, $head['canonical'], 'canonical drops tracking parameters');
            $this->assertStringEndsWith('/assets/img/share.png', $head['image']);
            $this->assertSame(1, substr_count($response->getContent(), '<h1'), "$url has one main heading");
            $this->assertFalse($response->headers->has('X-Robots-Tag'), "$url is indexable");
            $titles[] = $head['title'];
        }
        $this->assertSame(count($titles), count(array_unique($titles)), 'no two pages share a title');
        $this->assertFileExists(public_path('assets/img/share.png'));
    }

    public function test_structured_data_describes_the_business_and_matches_the_visible_faqs(): void
    {
        $home = collect($this->schema($this->get(route('home'))))->keyBy('@type');
        $this->assertSame('SureHelp Solutions', $home['Organization']['name']);
        $this->assertSame(config('company.address.zip'), $home['Organization']['address']['postalCode']);
        $this->assertContains('https://www.linkedin.com/company/surehelpsolution/', $home['Organization']['sameAs']);
        $this->assertArrayHasKey('WebSite', $home->all());
        $this->assertArrayNotHasKey('aggregateRating', $home['Organization'], 'no ratings we cannot show');

        // A service page: breadcrumbs, the service, and its FAQs exactly as shown on the page.
        $response = $this->get(route('pages.show', 'call-answering'));
        $graph = collect($this->schema($response))->keyBy('@type');
        $this->assertSame('Call Answering Service', $graph['BreadcrumbList']['itemListElement'][1]['name']);
        $this->assertSame('Call Answering Service', $graph['Service']['name']);
        foreach ($graph['FAQPage']['mainEntity'] as $question) {
            $response->assertSee($question['name']);
            $response->assertSee($question['acceptedAnswer']['text']);
        }
        $this->assertCount(count(config('site.faqs.call-answering')), $graph['FAQPage']['mainEntity']);

        // The FAQ page: every question on the page is in the structured data, and the other way round.
        $faq = $this->get(route('legal.faq'));
        $questions = collect($this->schema($faq))->firstWhere('@type', 'FAQPage')['mainEntity'];
        preg_match_all('#<div class="faq-item">\s*<h3>(.*?)</h3>#s', $faq->getContent(), $shown);
        $this->assertSame(array_map(fn ($q) => html_entity_decode($q), $shown[1]), array_column($questions, 'name'));
        $faq->assertSee('Google Calendar and Microsoft 365');
        $faq->assertDontSee('Apple Calendar');
    }

    public function test_sitemap_lists_the_public_pages_only(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        $this->assertContains(route('home'), $locs);
        $this->assertContains(route('pages.show', 'call-answering'), $locs);
        $this->assertContains(route('legal.faq'), $locs);
        $this->assertCount(1 + count(config('pages')) + 1 + 6, $locs);
        foreach ($locs as $loc) {
            $this->assertDoesNotMatchRegularExpression('#/(app|admin|agent|account|login)\b#', $loc);
            $this->get($loc)->assertOk();
        }
    }

    public function test_robots_keeps_crawlers_out_of_private_areas_and_points_to_the_sitemap(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

        foreach (['/app/', '/admin/', '/agent/', '/account/', '/api/'] as $path) {
            $this->assertStringContainsString("Disallow: {$path}\n", $robots);
        }
        $this->assertStringContainsString('Sitemap: '.route('seo.sitemap'), $robots);
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'a static file would hide the generated one');
    }

    public function test_private_pages_are_never_indexed(): void
    {
        $this->get(route('login'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/app')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($owner)->get(route('account.profile'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
