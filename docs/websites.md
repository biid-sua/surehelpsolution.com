# Connecting a website (G-3, D44)

Businesses connect their existing website under **Website** in the business portal. Owners and managers can change it; staff can't open it.

## What the business does

1. **Add the site** (up to 5): type the address, e.g. `riverplumbing.com`.
2. **Prove it's theirs**, in one of two ways, then press **Verify**:
   - a meta tag in the home page's `<head>`: `<meta name="surehelp-site-verification" content="…">`;
   - or a DNS TXT record on the domain: `surehelp-site-verification=…` (for `www.` sites, the bare domain works too).
3. **Paste the snippet** once into the site's header or footer code:
   ```html
   <script src="https://YOUR-DOMAIN/api/chat/widget.js" data-surehelp-chat="shw_…" async></script>
   ```
4. **Choose what it shows**: chat, online booking, click-to-call and a contact form. Visitors see one button that opens the parts switched on.

## The health and SEO check

It runs once the site is verified, again every 30 days (`websites:check`), and whenever the business presses **Check now**. It reads the home page and up to 9 linked pages, and checks up to 30 internal links. Findings come with a plain-language fix:

| Area | What's checked |
|---|---|
| Security | HTTPS |
| Search results | page titles, descriptions, one main heading, "noindex", robots.txt blocking everyone, sitemap |
| Phones and speed | the viewport (phone) setting, server response time, page size |
| Content | image descriptions (alt text), broken links |
| Business details | LocalBusiness structured data; business name, phone and town match the business profile |
| SureHelp | the snippet is on the site |

The score starts at 100 and loses 15 for each "fix soon" and 5 for each "worth fixing". It's guidance, not a ranking promise.

## How the snippet's booking and contact form work

| Part | Endpoint (public, per widget key) | Result |
|---|---|---|
| Booking | `GET /api/chat/{key}/booking/services`, `GET …/booking/slots?date=&service=`, `POST …/booking` | An appointment (pending unless the business confirms automatically), source `website`, under the same rules as agents |
| Contact form | `POST …/lead` | A call-back task (or follow-up when only an email is given) with the message and page |
| Click-to-call | `GET …/config` returns the business phone | A `tel:` link |

Both forms match or create the customer (source `website`). Protection:
- the widget's allowed websites and rate limits (D37);
- a hidden field that catches bots;
- 5 accepted submissions per visitor per hour.

## Security of the checks

Website addresses come from customers, so fetching them is locked down (`App\Services\Websites\SafeFetcher`):
- **Allowed requests:** http/https on the standard ports only.
- **Addresses:** the host must resolve only to public addresses, and the connection is pinned to the checked address.
- **Redirects:** every redirect is checked again, with 3 at most.
- **Limits:** 10 seconds and 2 MB per page.
- **What we keep:** we store only the findings, never page content.

## Deploying

- Migration `2026_10_23_000001`: `websites` table; `chat_widgets.features` and `bookings_need_confirmation`.
- Scheduler: `websites:check` daily at 07:20 UTC queues the monthly re-checks; the queue worker runs them.
- The server must be able to make outgoing HTTP(S) requests and DNS lookups.
