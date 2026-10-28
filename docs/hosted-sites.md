# Hosted websites (D99)

Businesses can have a website we host, made from a template and the details they've already given us,
and changed by asking in plain words. It lives under **Website builder** in the business portal.

## What the business does

1. **Chooses a look:** Bold (trades), Clean (home services) or Care (clinics and salons).
2. **Picks an address:** `rivera-plumbing.surehelp.site` once our sites domain is set up, or
   `surehelpsolution.com/site/rivera-plumbing` until then.
3. **Looks over the draft.** It's filled in from the business profile: name, description, active
   services, service area, real 4- and 5-star reviews, and the brand colour. Nothing is invented.
4. **Asks for changes**, for example "Make the headline about 24/7 emergency repairs", "Add a page about
   drain cleaning" or "Use a dark green colour". The AI makes the change in the background, usually in
   under a minute, and the preview updates.
5. **Publishes.** Visitors only see a version once it's published.
6. **Goes back** to any earlier version, or drops changes that aren't published yet.

Phone, email, address and opening hours always come from the business's details, so they're never out
of date. The main button books online (when the business takes online bookings) or calls. The SureHelp
snippet adds booking and chat.

## How it works

- **Content, not HTML.** A site is JSON (`App\Services\Sites\SiteContent`): a theme (colour and
  style) and up to 8 pages of up to 14 sections. Every section has a heading, text, a button label and
  items. The template decides how each type looks. The AI answers with the whole content in that
  shape, and the server cleans it: HTML is stripped, unknown types are dropped, lengths are capped and
  page addresses are made safe.
- **Versions.** `site_versions` keeps every change, numbered. The newest is the draft;
  `sites.published_version_id` is what visitors see. Restoring an old version adds it again as the
  newest, so history is never lost.
- **AI requests** (`site_edits`) are queued (`RunSiteEdit`) and record the tokens they used. The AI is
  told to use only the business's facts and never to write reviews. Requests that aren't about the
  website make no change. Demo businesses never call the AI.
- **Allowance.** `SITES_MONTHLY_TOKENS` (150,000) tokens per calendar month, or a plan's
  `site_ai_tokens` limit. A change usually uses 2,000 to 5,000. At most one change runs at a time, and 30
  requests an hour.
- **Who.** People with `integrations.view` see the builder; `integrations.manage` (owners, and
  managers if allowed) can change and publish. The `website_builder` feature can be limited to plans
  under *Feature access*.
- **When a site is offline:** while the business is paused or cancelled, when the feature is off for
  it (including after an unkept free trial, D97), or when the owner takes it offline.

## Settings (.env)

| Setting | Default | Meaning |
|---|---|---|
| `SITES_DOMAIN` | empty | e.g. `surehelp.site`. Needs a wildcard DNS record (`*.surehelp.site`) and a wildcard certificate pointing at this app. Empty: sites are served at `/site/{address}` |
| `SITES_MONTHLY_TOKENS` | `150000` | AI tokens per business per month |

## Not yet

- Businesses' own domains (needs the hosting decision: Cloudflare for SaaS, or Forge / Laravel Cloud).
- Buying AI credits (top-ups); for now each business has the monthly allowance.
- Photos and image uploads in sections.
