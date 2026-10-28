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
  `site_ai_tokens` limit. A change usually uses 2,000 to 5,000. Beyond the allowance, changes use AI
  credits ([ai-credits.md](ai-credits.md), D100). At most one change runs at a time, and 30
  requests an hour.
- **Who.** People with `integrations.view` see the builder; `integrations.manage` (owners, and
  managers if allowed) can change and publish. The `website_builder` feature can be limited to plans
  under *Feature access*.
- **Search engines:** public pages can be indexed (`X-Robots-Tag: all`); previews never are.
- **When a site is offline:** while the business is paused or cancelled, when the feature is off for
  it (including after an unkept free trial, D97), or when the owner takes it offline.

## Settings (.env)

| Setting | Default | Meaning |
|---|---|---|
| `SITES_DOMAIN` | empty | e.g. `surehelp.site`. Needs a wildcard DNS record (`*.surehelp.site`) and a wildcard certificate pointing at this app. Empty: sites are served at `/site/{address}` |
| `SITES_MONTHLY_TOKENS` | `150000` | AI tokens per business per month |
| `SITES_DOMAIN_DRIVER` | `manual` | who issues certificates for own domains: `manual` or `cloudflare` |
| `SITES_CNAME_TARGET` | empty | where owners point their `www` CNAME; empty hides own domains |
| `SITES_IP` | empty | this server's address, for bare domains (A records) |
| `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID` | empty | for the `cloudflare` driver |

## Own domains (D101)

Under **Your own domain** in the builder, the owner types their address (for example
`www.brightplumbing.com`) and adds one DNS record at the company they bought it from:

| Domain | Record |
|---|---|
| `www.` or another subdomain | `CNAME` from `www` to `SITES_CNAME_TARGET` (for example `sites.surehelp.site`) |
| a bare domain (`brightplumbing.com`) | `A` record `@` to `SITES_IP`; only offered when that's set, otherwise owners use the `www.` version and forward the bare domain at their domain company |

Pointing the domain at us is the proof it's theirs. We check every 10 minutes (`sites:check-domains`),
and **Check now** checks straight away. Once the DNS points here and the certificate is issued, the
site answers on the domain over HTTPS, the free address redirects there (301), and search tags use it.
Working domains are rechecked daily: one that stops pointing here goes back to waiting and the free
address serves again. While a domain waits, visitors there see a plain "being set up" note.

**Removing** the domain puts the site back on its free address.

### Setting up the server (once)

Choose who issues certificates with `SITES_DOMAIN_DRIVER`:

- **`cloudflare`** (Cloudflare for SaaS; about $0.10 per domain a month after the first 100):
  1. Put `surehelp.site` (or your sites domain) on Cloudflare and turn on **SSL/TLS › Custom Hostnames**.
  2. Add a proxied record `sites.surehelp.site` pointing to this server, and set it as the **fallback origin**.
  3. Create an API token with *SSL and Certificates: Edit* on that zone.
  4. Set `SITES_CNAME_TARGET=sites.surehelp.site`, `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID`.
- **`manual`** (the default; this server issues certificates):
  - With **Caddy**, use on-demand TLS and let it ask us first:
    `on_demand_tls { ask https://YOUR-APP/sites/domain-allowed }`. We answer yes only for domains a
    business has connected.
  - With **Forge** or another host, add each domain's certificate there when it's connected.
  - Set `SITES_CNAME_TARGET` to a host name pointing at this server, and `SITES_IP` to its address
    if bare domains should work.

Until `SITES_CNAME_TARGET` is set, the **Your own domain** card doesn't show.

## Not yet
- Photos and image uploads in sections.
