<?php

namespace App\Services\Seo;

use App\Models\SeoTask;
use App\Models\Website;

/**
 * The SEO tasks the AI can do (D57): for each, what it's for, the request written for the model, and
 * the exact JSON shape that must come back (structured output). Counts and lengths are asked for in
 * the request and enforced in code afterwards, since schemas can't limit them.
 */
class SeoTaskCatalog
{
    public const TYPES = [
        'keyword_plan' => [
            'label' => 'Keyword plan',
            'description' => 'The searches your customers make, which page should win each one, and content ideas.',
            'needs_pages' => false,
        ],
        'page_meta' => [
            'label' => 'Page titles and descriptions',
            'description' => 'New search-result titles, descriptions and main headings for the pages of your site we checked.',
            'needs_pages' => true,
        ],
        'local_page' => [
            'label' => 'Local service page',
            'description' => 'A complete page for one service in one town, ready to publish.',
            'needs_pages' => false,
        ],
        'faq' => [
            'label' => 'FAQ with search markup',
            'description' => 'Questions customers ask, with answers and the code that lets Google show them.',
            'needs_pages' => false,
        ],
        'gbp_posts' => [
            'label' => 'Google Business Profile posts',
            'description' => 'Four short posts for your Google Business Profile.',
            'needs_pages' => false,
        ],
    ];

    /** Shared, stable instructions (cached by the provider together with the business facts). */
    public function instructions(string $facts): string
    {
        return <<<TXT
        You are the SEO specialist at SureHelp Solutions, doing search-engine work for one local service business. What you write is published on the business's website or Google Business Profile, so it must be accurate and ready to use.

        Rules:
        - Use only the facts below. Never invent awards, years in business, prices, licences, guarantees, reviews, staff names or places the business doesn't serve. If a fact you'd like is missing, write without it.
        - Write for people first: plain, specific, helpful language. Use each important phrase naturally, never stuffed or repeated for its own sake.
        - Page titles: at most 60 characters, service first, then the town, then the business name when it fits.
        - Meta descriptions: 120–155 characters, saying what the customer gets and why to choose this business, ending with a reason to call or book.
        - One main heading (H1) per page, different from the title but close to it.
        - Match the spelling and conventions of the business's country (US English when unclear).
        - Answer only with the JSON asked for.

        {$facts}
        TXT;
    }

    /** @return array{prompt: string, schema: array<string, mixed>} */
    public function request(SeoTask $task, ?Website $website): array
    {
        $input = $task->input ?? [];

        return match ($task->type) {
            'keyword_plan' => [
                'prompt' => "Make a keyword plan for this business: 10 to 15 searches real customers in its area are likely to make, from urgent (\"emergency plumber near me\") to research (\"how much does a water heater cost\"). For each, say which page of the business's site should win it (an existing one from the list, or a new page to create), how important it is, and why. Then suggest 3 to 5 content ideas that would bring in those customers.\n\n"
                    .$this->pageList($website).(filled($input['focus'] ?? null) ? "\nFocus especially on: ".$input['focus'] : ''),
                'schema' => $this->object([
                    'summary' => ['type' => 'string', 'description' => 'Two or three sentences on where the opportunity is.'],
                    'keywords' => $this->list($this->object([
                        'keyword' => ['type' => 'string'],
                        'intent' => ['type' => 'string', 'enum' => ['urgent', 'local', 'research', 'comparison', 'brand']],
                        'priority' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'target_page' => ['type' => 'string', 'description' => 'URL of an existing page, or the title of a new page to create.'],
                        'why' => ['type' => 'string'],
                    ])),
                    'content_ideas' => $this->list($this->object(['title' => ['type' => 'string'], 'why' => ['type' => 'string']])),
                ]),
            ],
            'page_meta' => [
                'prompt' => "For each page below, write a better search-result title, meta description and main heading (H1), keeping what already works. Say briefly why each change helps. Return the pages in the same order with the same URLs.\n\n"
                    .json_encode(array_slice($input['pages'] ?? [], 0, 10), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'schema' => $this->object([
                    'pages' => $this->list($this->object([
                        'url' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'h1' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                    ])),
                ]),
            ],
            'local_page' => [
                'prompt' => 'Write a complete web page for the service "'.($input['service'] ?? '').'" in "'.($input['area'] ?? '').'". '
                    .'Give it a URL slug, title, meta description and H1; a short introduction; 3 to 5 sections with headings covering what the service includes, the area served, why choose this business and what happens when you book; 3 to 5 FAQs; and a closing call to action. 500 to 900 words in total, written for that town without pretending to have an office there unless the address says so.',
                'schema' => $this->object([
                    'slug' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'h1' => ['type' => 'string'],
                    'intro' => ['type' => 'string'],
                    'sections' => $this->list($this->object(['heading' => ['type' => 'string'], 'body' => ['type' => 'string']])),
                    'faq' => $this->list($this->object(['question' => ['type' => 'string'], 'answer' => ['type' => 'string']])),
                    'call_to_action' => ['type' => 'string'],
                ]),
            ],
            'faq' => [
                'prompt' => 'Write 6 to 8 questions customers of this business really ask before booking, each with a clear answer of 40 to 90 words that uses only the facts given.'
                    .(filled($input['topic'] ?? null) ? ' Focus on: '.$input['topic'].'.' : ''),
                'schema' => $this->object([
                    'faq' => $this->list($this->object(['question' => ['type' => 'string'], 'answer' => ['type' => 'string']])),
                ]),
            ],
            'gbp_posts' => [
                'prompt' => 'Write 4 Google Business Profile posts for the coming month: varied (a service highlight, a seasonal tip, a reason to choose the business, a reminder to book), each 80 to 200 words with a short title and the button that fits best.',
                'schema' => $this->object([
                    'posts' => $this->list($this->object([
                        'title' => ['type' => 'string'],
                        'body' => ['type' => 'string'],
                        'button' => ['type' => 'string', 'enum' => ['CALL', 'BOOK', 'LEARN_MORE']],
                    ])),
                ]),
            ],
            default => throw new \InvalidArgumentException("Unknown SEO task [{$task->type}]."),
        };
    }

    /**
     * Keep the answer to what was asked: trimmed text, sane counts and lengths.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function tidy(string $type, array $data): array
    {
        $clip = fn (mixed $v, int $max) => mb_substr(trim((string) $v), 0, $max);

        return match ($type) {
            'keyword_plan' => [
                'summary' => $clip($data['summary'] ?? '', 1000),
                'keywords' => array_slice($data['keywords'] ?? [], 0, 20),
                'content_ideas' => array_slice($data['content_ideas'] ?? [], 0, 8),
            ],
            'page_meta' => ['pages' => array_map(fn (array $p) => [
                'url' => $clip($p['url'] ?? '', 500), 'title' => $clip($p['title'] ?? '', 70), 'description' => $clip($p['description'] ?? '', 170),
                'h1' => $clip($p['h1'] ?? '', 120), 'why' => $clip($p['why'] ?? '', 400),
            ], array_slice($data['pages'] ?? [], 0, 10))],
            'local_page' => array_merge($data, [
                'slug' => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($data['slug'] ?? ''))), '-'),
                'title' => $clip($data['title'] ?? '', 70),
                'description' => $clip($data['description'] ?? '', 170),
                'sections' => array_slice($data['sections'] ?? [], 0, 8),
                'faq' => array_slice($data['faq'] ?? [], 0, 8),
            ]),
            'faq' => ['faq' => array_slice($data['faq'] ?? [], 0, 10)],
            'gbp_posts' => ['posts' => array_map(fn (array $p) => [
                'title' => $clip($p['title'] ?? '', 80), 'body' => $clip($p['body'] ?? '', 1500), 'button' => $p['button'] ?? 'LEARN_MORE',
            ], array_slice($data['posts'] ?? [], 0, 6))],
            default => $data,
        };
    }

    /**
     * schema.org FAQPage markup for a list of questions (built in code, not by the AI).
     *
     * @param  list<array{question: string, answer: string}>  $faq
     */
    public static function faqJsonLd(array $faq): string
    {
        return '<script type="application/ld+json">'."\n".json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $q) => [
                '@type' => 'Question',
                'name' => $q['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['answer']],
            ], $faq),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n</script>";
    }

    private function pageList(?Website $website): string
    {
        $pages = $website?->health['pages'] ?? [];
        if ($pages === []) {
            return 'The business\'s website pages aren\'t known yet.';
        }

        return "The website's pages we checked:\n".collect($pages)->map(fn (array $p) => '- '.$p['url'].' ("'.($p['title'] ?? 'no title').'")')->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function list(array $items): array
    {
        return ['type' => 'array', 'items' => $items];
    }
}
