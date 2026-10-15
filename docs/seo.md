# SEO service (D57)

Businesses take our SEO service under **SEO** in the business portal. AI does the SEO work within a monthly token allowance, and the **Results** tab shows the difference before and after.

## Setting it up (once)

1. **Anthropic key:** set `ANTHROPIC_API_KEY` (the same key the AI assistant uses). Without it, tasks can't start, and the page says the AI isn't set up.
2. **The add-on:** in *Admin › Billing › Add-ons*, create an add-on with the slug **`seo_service`**, a name ("SEO service") and a monthly price. Businesses can then turn it on themselves from the SEO page. You can also put `seo_service` in a plan's features, or switch it on for one business on its page.
3. **Google Search Console** (for the clicks, times shown and position statistics):
   - In the Google Cloud project used for calendar sync, enable the **Google Search Console API**.
   - Add the redirect URI `{APP_URL}/app/seo/search-console/callback` to the OAuth client.
   - Add the scope `https://www.googleapis.com/auth/webmasters.readonly` to the OAuth consent screen. Google must verify the app for this scope before businesses outside your test users can connect.
4. **Optional settings** (`.env`):
   - `SEO_MONTHLY_TOKENS`: the default monthly allowance (250,000).
   - A plan limit `seo_ai_tokens`: a different allowance for that plan.

## What the business does

- **Turn it on** from the offer card. This needs billing permission and an active plan.
- **Settings:** the towns to win customers from, the searches it wants to be found for, autopilot on or off, and connecting Google Search Console.
- **SEO work:**
  - Run a task, or let autopilot do it on the 1st of each month.
  - Read the result, approve it or set it aside, and copy it.
  - After the change is on the website, mark it **live**.
- **Results:**
  - Tiles for clicks, times shown in Google, average position, website health and website leads.
  - Each tile shows before (the 28 days before SEO work started) and now (the latest 28 days).
  - Weekly charts with dashed markers for the changes that went live.
  - The searches people used to find the site.

## What staff do

*Admin › SEO service*:
- **Approved, waiting to go live:** put the change on the business's site (or check that they did), then **Mark live**.
- **Businesses:** tokens used this month, an allowance for one business (blank means the plan's or the default), and **Run a task** for them.

## Notes

- Search Console data is about 2–3 days behind, and search results usually take 2–8 weeks to respond to a change.
- Each task uses roughly 5,000–20,000 tokens. Results are checked against the business's facts, but someone should still read them before publishing.
