# Website sales chat (D78)

SureHelp's own assistant ("Ava") on the public website. It answers questions about SureHelp, captures
leads and books 30-minute demo calls with the team. It is separate from the businesses' own website chat
(the Inbox `web_chat` channel, D37).

## What the visitor gets

- A chat button on every public page. "Book a demo" buttons (`data-sales-chat-open="demo"`) open it
  straight on the time picker.
- Quick replies: Book a demo, Pricing, How it works, Start a free trial, Talk to a person.
- A time picker with the team's open times. The visitor taps a time, then gives their name and email in a
  short form (phone and company are optional), and is booked. They get an email invitation with a calendar
  file.
- Free-text questions answered by the AI, in the visitor's language. It picks up details they mention
  (kind of business, call volume, what they use today, when they want to start) without asking for them
  one by one.
- "Talk to a person" hands the chat to the team. The team replies from Admin › Website enquiries; the
  visitor sees the reply live, or gets it by email if they've left. The chat remembers the visitor (a token
  in their browser), so they can come back to it.
- The text-message consent box is optional and unticked. The AI never decides consent.

## What the team gets

- Every lead becomes a website enquiry (source "Chat") with:
  - a hot, warm or cold score (simple rules in `LeadScore`, no AI);
  - what the chat learned;
  - the booked demo time;
  - the campaign (UTM) and the page;
  - the full transcript.
- Email alerts to `SALES_CHAT_NOTIFY` (default `MAIL_CONTACT_TO`) for:
  - a new lead;
  - a booked demo;
  - a chat handed to the team.

## Keeping the AI cheap

- Buttons, forms and the time picker never call the AI. Only free text does.
- The instructions and tools form one stable, cached prefix. The volatile part (time, page, what we know)
  is a few lines.
- Only the last 12 messages are sent. What the visitor told us travels as short facts, not long history.
- The defaults are low effort, a 2,000-token output cap and at most 4 tool rounds per answer.
- Caps:
  - AI answers per chat: `SALES_CHAT_REPLIES_PER_CHAT`, default 30.
  - Chats per IP per day: `SALES_CHAT_CHATS_PER_IP`, default 10.
  - Daily token budget across all chats: `SALES_CHAT_DAILY_TOKENS`, default 2,000,000 input + output tokens.
- Rate limits per IP and per chat, a 600-character message limit, and a honeypot on the form.
- With no `ANTHROPIC_API_KEY`, or once the budget is spent, the chat keeps working on buttons and forms,
  with keyword answers for pricing, demos and "a person".
- Model: `SALES_CHAT_MODEL` (default: the app's `AI_MODEL`). Setting `SALES_CHAT_MODEL=claude-haiku-5-5`
  cuts the cost to a small fraction and suits this chat. Effort: `SALES_CHAT_EFFORT` (default `low`).
- Token use is stored per chat (`sales_chats.input_tokens`, `output_tokens`, `cache_read_tokens`).

## Settings (`config/sales.php`)

- Demo hours, length, notice, days ahead, timezone (`SALES_DEMO_TIMEZONE`), how many demos can run at once
  (`SALES_DEMO_CAPACITY`) and the call location (`SALES_DEMO_LOCATION`).
- Plans and facts the assistant may use. **Keep the plans in step with the pricing section of
  `home.blade.php`.** The FAQ comes from `config/site.php` (`faq_page`).
- `SALES_CHAT_ENABLED=false` removes the chat from the site.
- Chats that never became a lead are deleted after `retention_days` (90) by `privacy:run`.

## When the chat doesn't answer properly

Run `php artisan sales-chat:check`. It checks the chat is on, the tables exist (`php artisan migrate`),
the cache works, and makes one short live AI call. It names the fix for a failed call. On Windows, the
usual one is the PHP CA bundle: set `curl.cainfo` and `openssl.cafile` in `php.ini` to a downloaded
`cacert.pem`.

With `APP_DEBUG=true` (never in production), the chat itself shows a yellow developer note when the AI
didn't answer, and the browser console logs it. Without `ANTHROPIC_API_KEY`, typed questions get
keyword answers ("Good question. I can show pricing…"). That's expected, not a fault.

## Code

- `App\Http\Controllers\SalesChatController`: the routes under `/api/sales-chat/*`. Requests must come from
  our own site, are sessionless and are left out of CSRF checks.
- `App\Services\Sales\SalesChatDesk`: buttons, forms, bookings, hand-over, enquiries and alerts.
- `SalesAssistant`: the AI turn and its caps. `SalesBrain`: the instructions and context. `DemoSlots`: open
  times and locked booking. `LeadScore`: the score.
- `public/assets/js/sales-chat.js` and `css/sales-chat.css`, plus `resources/views/partials/sales-chat.blade.php`:
  the widget (plain JS, CSP-safe, follows the site theme).
- `App\Console\Commands\SalesChatCheck`: `php artisan sales-chat:check`.
- Tests: `tests/Feature/SalesChatTest.php`.
