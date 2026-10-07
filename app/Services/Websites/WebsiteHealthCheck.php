<?php

namespace App\Services\Websites;

use App\Models\BusinessLocation;
use App\Models\BusinessProfile;
use App\Models\ChatWidget;
use App\Models\Website;

/**
 * The website health and SEO check (spec §41B): what a search engine and a visitor on a phone run
 * into, in plain language with the fix. Reads the home page and up to 9 more pages linked from it,
 * checks up to 30 internal links, robots.txt and the sitemap. Makes no ranking promises (§39).
 *
 * Each finding is "problem" (fix soon), "warning" (worth fixing) or "good".
 */
class WebsiteHealthCheck
{
    public const MAX_PAGES = 10;

    public const MAX_LINKS = 30;

    /** Schema.org types that describe a local business well enough. */
    private const BUSINESS_TYPES = ['LocalBusiness', 'HomeAndConstructionBusiness', 'Plumber', 'Electrician', 'HVACBusiness', 'RoofingContractor',
        'GeneralContractor', 'HousePainter', 'Locksmith', 'MovingCompany', 'ProfessionalService', 'LegalService', 'Attorney', 'Dentist',
        'MedicalBusiness', 'MedicalClinic', 'Physician', 'HealthAndBeautyBusiness', 'BeautySalon', 'HairSalon', 'DaySpa', 'AutoRepair',
        'AutomotiveBusiness', 'CleaningService', 'FinancialService', 'AccountingService', 'RealEstateAgent', 'Restaurant', 'Store',
        'VeterinaryCare', 'ChildCare', 'EmergencyService', 'Organization'];

    public function __construct(private readonly SafeFetcher $fetcher) {}

    public function run(Website $website): Website
    {
        $home = $this->fetcher->get($website->url);
        if (! $home->ok() || ! $home->isHtml()) {
            $website->forceFill([
                'last_checked_at' => now(),
                'last_error' => $home->error ?? "Your home page answered with an error ({$home->status}).",
            ])->save();

            return $website;
        }

        $homePage = new HtmlPage($home->url, $home->body);
        $pages = [$homePage];
        $internal = array_values(array_filter($homePage->links($this->fetcher), fn (string $l) => $this->sameSite($l, $website->host)));

        foreach (array_slice(array_values(array_filter($internal, fn (string $l) => $this->looksLikePage($l) && rtrim($l, '/') !== rtrim($home->url, '/'))), 0, self::MAX_PAGES - 1) as $link) {
            $result = $this->fetcher->get($link);
            if ($result->ok() && $result->isHtml()) {
                $pages[] = new HtmlPage($result->url, $result->body);
            }
        }

        $findings = [
            ...$this->https($home),
            ...$this->titles($pages),
            ...$this->descriptions($pages),
            ...$this->headings($pages),
            ...$this->mobile($homePage),
            ...$this->speed($home),
            ...$this->images($pages),
            ...$this->brokenLinks($pages, $website->host),
            ...$this->indexing($homePage, $website),
            ...$this->structuredData($pages),
            ...$this->businessDetails($homePage, $website),
            ...$this->snippet($home->body, $website),
        ];

        $score = 100;
        foreach ($findings as $f) {
            $score -= ['problem' => 15, 'warning' => 5][$f['status']] ?? 0;
        }

        $website->forceFill([
            'last_checked_at' => now(),
            'last_error' => null,
            'health_score' => max(0, $score),
            'health' => ['checked_pages' => array_map(fn (HtmlPage $p) => $p->url, $pages), 'findings' => $findings],
        ])->save();

        return $website;
    }

    /** @return list<array<string, mixed>> */
    private function https(FetchResult $home): array
    {
        return str_starts_with($home->url, 'https://')
            ? [$this->good('https', 'Your site uses HTTPS')]
            : [$this->problem('https', 'Your site doesn\'t use HTTPS', 'Browsers mark it "Not secure" and search engines rank it lower. Ask your web host to turn on a free HTTPS certificate (Let\'s Encrypt) and send all visitors to the https:// address.')];
    }

    /** @param list<HtmlPage> $pages @return list<array<string, mixed>> */
    private function titles(array $pages): array
    {
        $missing = $odd = [];
        foreach ($pages as $page) {
            $title = $page->title();
            if ($title === null) {
                $missing[] = $page->url;
            } elseif (mb_strlen($title) < 10 || mb_strlen($title) > 65) {
                $odd[] = $page->url;
            }
        }

        return array_values(array_filter([
            $missing ? $this->problem('titles', 'Some pages have no title', 'The title is the blue headline in search results. Give each page a unique title of 10–65 characters, like "Emergency Plumber in Austin | Rivera Plumbing".', $missing) : null,
            $odd ? $this->warning('title_length', 'Some titles are too short or too long', 'Keep titles between 10 and 65 characters so search engines show them in full. Lead with the service and the town.', $odd) : null,
            ! $missing && ! $odd ? $this->good('titles', 'Every page has a good title') : null,
        ]));
    }

    /** @param list<HtmlPage> $pages @return list<array<string, mixed>> */
    private function descriptions(array $pages): array
    {
        $missing = $odd = [];
        foreach ($pages as $page) {
            $description = $page->meta('description');
            if (blank($description)) {
                $missing[] = $page->url;
            } elseif (mb_strlen($description) < 50 || mb_strlen($description) > 170) {
                $odd[] = $page->url;
            }
        }

        return array_values(array_filter([
            $missing ? $this->warning('descriptions', 'Some pages have no description', 'The description is the grey text under your title in search results. Write one or two sentences (50–160 characters) per page saying what you do, where, and how to book.', $missing) : null,
            $odd ? $this->warning('description_length', 'Some descriptions are too short or too long', 'Aim for 50–160 characters so search engines show the whole description.', $odd) : null,
            ! $missing && ! $odd ? $this->good('descriptions', 'Every page has a description') : null,
        ]));
    }

    /** @param list<HtmlPage> $pages @return list<array<string, mixed>> */
    private function headings(array $pages): array
    {
        $none = $many = [];
        foreach ($pages as $page) {
            $count = $page->count('//h1');
            if ($count === 0) {
                $none[] = $page->url;
            } elseif ($count > 1) {
                $many[] = $page->url;
            }
        }

        return array_values(array_filter([
            $none ? $this->warning('h1_missing', 'Some pages have no main heading', 'Give each page one main heading (H1) that says what the page is about, for example "Water Heater Repair in Austin".', $none) : null,
            $many ? $this->warning('h1_many', 'Some pages have more than one main heading', 'Use one main heading (H1) per page and smaller headings (H2, H3) for sections.', $many) : null,
            ! $none && ! $many ? $this->good('headings', 'Every page has one main heading') : null,
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function mobile(HtmlPage $home): array
    {
        return $home->meta('viewport') !== null
            ? [$this->good('mobile', 'Your site is set up for phones')]
            : [$this->problem('mobile', 'Your site isn\'t set up for phones', 'Most people look you up on a phone. Your site is missing the "viewport" setting, so it shows tiny text on phones. Ask your web designer to add <meta name="viewport" content="width=device-width, initial-scale=1"> and check the layout on a phone.')];
    }

    /** @return list<array<string, mixed>> */
    private function speed(FetchResult $home): array
    {
        $kb = (int) round(strlen($home->body) / 1024);

        return array_values(array_filter([
            $home->milliseconds > 3000 ? $this->warning('speed', 'Your home page is slow to answer', "It took {$this->seconds($home->milliseconds)} seconds before your server started answering. Under 1 second is good. Ask your host about caching, or a faster plan.") : null,
            $home->truncated || $kb > 1500 ? $this->warning('page_size', 'Your home page is very large', 'The page itself is over 1.5 MB before images. Large pages load slowly on phones. Remove unused code, plugins and inline images.') : null,
            $home->milliseconds <= 3000 && ! $home->truncated && $kb <= 1500 ? $this->good('speed', 'Your home page answers quickly') : null,
        ]));
    }

    /** @param list<HtmlPage> $pages @return list<array<string, mixed>> */
    private function images(array $pages): array
    {
        $count = 0;
        $where = [];
        foreach ($pages as $page) {
            $n = $page->count('//img[not(@alt) or normalize-space(@alt)=""]');
            if ($n > 0) {
                $count += $n;
                $where[] = $page->url;
            }
        }

        return $count > 0
            ? [$this->warning('alt_text', "{$count} ".str('image')->plural($count).' have no description', 'Describe each photo in its "alt text" (for example "Technician repairing a water heater"). It helps people using screen readers and helps your photos appear in image search.', $where)]
            : [$this->good('alt_text', 'Images are described')];
    }

    /** @param list<HtmlPage> $pages @return list<array<string, mixed>> */
    private function brokenLinks(array $pages, string $host): array
    {
        $links = [];
        foreach ($pages as $page) {
            foreach ($page->links($this->fetcher) as $link) {
                if ($this->sameSite($link, $host)) {
                    $links[$link] = true;
                }
            }
        }

        $broken = [];
        foreach (array_slice(array_keys($links), 0, self::MAX_LINKS) as $link) {
            $result = $this->fetcher->get($link, headOnly: true);
            if (in_array($result->status, [403, 405, 501], true)) {
                $result = $this->fetcher->get($link);    // some servers refuse HEAD
            }
            if ($result->error !== null || $result->status === null || $result->status >= 400) {
                $broken[] = $link;
            }
        }

        return $broken
            ? [$this->problem('broken_links', count($broken).' broken '.str('link')->plural(count($broken)), 'These links lead to a missing page. Visitors give up and search engines trust the site less. Fix or remove them.', $broken)]
            : [$this->good('broken_links', 'No broken links found')];
    }

    /** @return list<array<string, mixed>> */
    private function indexing(HtmlPage $home, Website $website): array
    {
        $findings = [];
        if (str_contains(strtolower((string) $home->meta('robots')), 'noindex')) {
            $findings[] = $this->problem('noindex', 'Your home page asks search engines not to list it', 'It has a "noindex" setting, so it won\'t appear in Google. This is often left over from building the site. Remove it in your site builder\'s SEO settings.');
        }

        $robots = $this->fetcher->get($website->url.'robots.txt');
        $robotsText = $robots->ok() ? $robots->body : '';
        if ($this->blocksEveryone($robotsText)) {
            $findings[] = $this->problem('robots_blocked', 'Your robots.txt blocks search engines', 'Its "Disallow: /" line tells every search engine to stay away from the whole site. Remove that line.');
        }

        $sitemap = str_contains(strtolower($robotsText), 'sitemap:') || $this->fetcher->get($website->url.'sitemap.xml')->ok();
        $findings[] = $sitemap
            ? $this->good('sitemap', 'Your site has a sitemap')
            : $this->warning('sitemap', 'Your site has no sitemap', 'A sitemap (sitemap.xml) lists your pages so search engines find them all. Most site builders create one with a setting; then add it in Google Search Console.');

        if (! array_filter($findings, fn ($f) => $f['status'] === 'problem')) {
            $findings[] = $this->good('indexing', 'Search engines are allowed to list your site');
        }

        return $findings;
    }

    /** @param list<HtmlPage> $pages @return list<array<string, mixed>> */
    private function structuredData(array $pages): array
    {
        $types = array_merge(...array_map(fn (HtmlPage $p) => $p->structuredDataTypes(), $pages));
        $local = array_intersect(array_map(fn ($t) => preg_replace('#^https?://schema\.org/#', '', $t), $types), self::BUSINESS_TYPES);

        return $local !== []
            ? [$this->good('structured_data', 'Search engines can read your business details')]
            : [$this->warning('structured_data', 'Search engines can\'t read your business details', 'Add "LocalBusiness" structured data (name, address, phone, hours, area served). It helps Google show your hours and phone number in results. Many site builders have an SEO setting or plugin for it.')];
    }

    /** @return list<array<string, mixed>> */
    private function businessDetails(HtmlPage $home, Website $website): array
    {
        $organization = $website->organization;
        $profile = BusinessProfile::query()->forOrganization($organization)->first();
        $location = BusinessLocation::query()->forOrganization($organization)->orderByDesc('is_primary')->orderBy('id')->first();
        $text = mb_strtolower($home->visibleText());
        $digits = (string) preg_replace('/\D+/', '', $text);
        $findings = [];

        $name = $profile->display_name ?? $organization->name;
        if ($name !== '' && ! str_contains($text, mb_strtolower($name))) {
            $findings[] = $this->warning('nap_name', 'Your business name isn\'t on your home page', "We looked for \"{$name}\". Use the same name everywhere (site, Google Business Profile, directories) so search engines know it's one business.");
        }

        $phone = substr((string) preg_replace('/\D+/', '', (string) $profile?->phone), -10);
        if (strlen($phone) === 10 && ! str_contains($digits, $phone)) {
            $findings[] = $this->problem('nap_phone', 'Your phone number isn\'t on your home page', 'Callers can\'t find how to reach you. Show your number at the top of every page and make it a tap-to-call link. Use the same number as on your Google Business Profile.');
        }

        $place = array_filter([$location?->postal_code, $location?->city]);
        if ($place !== [] && ! array_filter($place, fn ($p) => str_contains($text, mb_strtolower((string) $p)))) {
            $findings[] = $this->warning('nap_address', 'Your town or address isn\'t on your home page', 'Mention where you work (town, area or full address). It helps people and search engines know you serve their area.');
        }

        return $findings ?: [$this->good('nap', 'Your name, phone and area match your business profile')];
    }

    /** @return list<array<string, mixed>> */
    private function snippet(string $html, Website $website): array
    {
        $key = ChatWidget::query()->forOrganization($website->organization_id)->value('public_key');

        return $key && str_contains($html, $key)
            ? [$this->good('snippet', 'SureHelp is on your site')]
            : [$this->warning('snippet', 'SureHelp isn\'t on your site yet', 'Add your SureHelp snippet so visitors can chat, book and call you from any page. Copy it from the Website page.')];
    }

    /**
     * True when robots.txt tells every crawler ("User-agent: *") to stay off the whole site.
     */
    private function blocksEveryone(string $robots): bool
    {
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\R/', $robots) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if (! str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = $value;
            } elseif ($field === 'disallow' || $field === 'allow') {
                $inRules = true;
                if ($field === 'disallow' && $value === '/' && in_array('*', $agents, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sameSite(string $url, string $host): bool
    {
        $linkHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $bare = preg_replace('/^www\./', '', $host);

        return $linkHost !== '' && preg_replace('/^www\./', '', $linkHost) === $bare;
    }

    private function looksLikePage(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return ! preg_match('/\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|mp4|mp3|css|js|xml|txt)$/', $path);
    }

    private function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1);
    }

    /** @param list<string> $pages @return array<string, mixed> */
    private function problem(string $key, string $title, string $detail, array $pages = []): array
    {
        return ['key' => $key, 'status' => 'problem', 'title' => $title, 'detail' => $detail, 'pages' => array_slice($pages, 0, 10)];
    }

    /** @param list<string> $pages @return array<string, mixed> */
    private function warning(string $key, string $title, string $detail, array $pages = []): array
    {
        return ['key' => $key, 'status' => 'warning', 'title' => $title, 'detail' => $detail, 'pages' => array_slice($pages, 0, 10)];
    }

    /** @return array<string, mixed> */
    private function good(string $key, string $title): array
    {
        return ['key' => $key, 'status' => 'good', 'title' => $title, 'detail' => '', 'pages' => []];
    }
}
