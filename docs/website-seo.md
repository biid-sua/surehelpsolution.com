# SEO for surehelpsolution.com (D58)

## What's in the code

| Area | Where |
|---|---|
| Search titles, descriptions, FAQs and business facts | `config/site.php` |
| Head tags (canonical, robots, Open Graph, X card) | `resources/views/partials/site-head.blade.php` |
| Structured data (Organization, WebSite, Service, BreadcrumbList, FAQPage) | `app/Support/SiteSchema.php` |
| `/sitemap.xml` and `/robots.txt` | `app/Http/Controllers/SitemapController.php` |
| `noindex` on everything that isn't the public site | `app/Http/Middleware/SearchIndexing.php` |
| Tests | `tests/Feature/PublicSiteSeoTest.php` |

When you add a menu page in `config/pages.php`:
1. Add its title and description under `meta` in `config/site.php`. The test fails until every page has one.
2. Optionally, add FAQs under `faqs` in the same file.

## What only you can do

Search rankings depend on more than the website's code. In order of impact:

1. **Google Search Console:**
   - Add the domain `surehelpsolution.com` as a property and verify it (a DNS TXT record).
   - Submit `https://surehelpsolution.com/sitemap.xml`.
   - Use *URL inspection* → *Request indexing* on the home page and the main service pages.
   - Do the same in **Bing Webmaster Tools**; you can import the property from Search Console.
2. **One address for the site:**
   - Make sure `http://`, `www.` and `https://` all redirect (301) to a single address, e.g. `https://surehelpsolution.com/`, at the web server or Cloudflare.
   - Set `APP_URL` to the same address.
3. **Google Business Profile:**
   - Create or claim it as a service-area business (hide the mailbox address).
   - Category: "Telephone answering service".
   - Use the same name, phone and website as the site.
   - Ask happy customers for reviews there.
4. **Consistent business details everywhere** (name, phone, email):
   - The site uses "SureHelp Solutions", +1 (858) 321-3947 and `info@surehelpsolutions.com`. Note that the email domain is *surehelpsolutions.com*, with an "s", while the website is *surehelpsolution.com*.
   - The FAQ page's contact box also lists 1-800-SUREHELP (1-800-787-3435). Keep only the numbers that actually ring.
5. **Links from other websites:** listings on Clutch, G2, Capterra, Yelp, the BBB, local chambers of commerce and industry associations, plus partner and customer websites linking back.
6. **Content, every month:**
   - Real case studies, as soon as customers agree.
   - One helpful article per month answering a question customers search for, e.g. "How much does an answering service cost?", "Answering service vs virtual receptionist" or "How to forward business calls after hours".
   - A pricing page with real prices: "answering service pricing" is one of the most searched questions in this market.
7. **Claims to confirm before Google and customers rely on them:**
   - The FAQ says SureHelp is HIPAA compliant, uses SOC 2 compliant data centres, and offers 24/7 phone, email and chat support with dedicated account managers.
   - The home page shows "95%+ answered live", "save up to 60%", "30% more appointments", and testimonials with stock photos.
   - Keep only what you can back up. Made-up reviews or statistics can hurt rankings and trust.

## Checking it

- Google's Rich Results Test (`search.google.com/test/rich-results`) on `/`, `/call-answering` and `/faq`.
- PageSpeed Insights on the home page, for mobile.
- Search Console → *Pages* (indexing) and *Performance* (clicks, impressions, position), a few weeks after submitting.

Nobody can guarantee a #1 ranking. The code now gives Google everything it needs; rankings then come from the steps above, kept up over months.
