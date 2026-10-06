# Inbox and AI assistant

Every customer message (website chat, Facebook Messenger, Instagram direct messages) arrives in **Inbox** in the business portal. The business answers from there. It can also let an AI assistant answer: the assistant uses the business's own information, books into its real calendar, and passes anything sensitive to a person. Why it works this way: decisions D37–D39 in [decisions.md](decisions.md); requirements in spec §26 and §26A.

## What a business sees

| Screen | What it does |
|---|---|
| **Inbox › Conversations** | List filtered by *Needs you* (default), *AI handling*, *Open*, *Closed*, *All*, by channel, and by name. The conversation shows who wrote each message (customer, a named team member, the AI, or a team note). From here the team can reply, add a team-only note, assign, close or reopen, *Take over* or *Hand back to AI*, open the customer record, see "What the AI did" on each AI reply, rate AI replies (👍 / 👎) and say what they should have said |
| **Inbox › AI assistant** | Switch it on, set its name and tone, and choose a mode per channel: *off*, *suggest replies* or *reply automatically*. Decide whether it may book, and whether its bookings wait for confirmation. Edit the hand-over message and extra instructions. Approve guidelines. See 30-day results. *Stop the AI now* turns it off everywhere at once |
| **Inbox › Channels** | Website chat: snippet to copy, on/off, title, greeting, colour, and the websites allowed to show it. Messenger / Instagram: switch messages on per connected Page or account |

The navigation item is **Inbox** (permission `messages.view`; staff included). Replying needs `messages.send`. AI settings need `ai.view` to see and `ai.manage` to change (owners and managers). Channel settings need `integrations.manage`.

## How a message flows

1. **A message arrives** (`ReceiveMessage`). It is stored once: Meta's message ids are unique per conversation, so retried webhooks don't duplicate. The conversation reopens if it was closed, and the customer is matched or created when they gave an email or phone. New conversations go on the customer's timeline.
2. **Who answers.**
   - If the assistant is in *auto* for that channel, and nobody on the team has taken over, it answers. The job runs right after the response is sent, so it works without a queue worker.
   - Otherwise the conversation is marked **Needs you**. The team gets a `message.received` notification, at most once every 10 minutes per conversation.
   - In *suggest* mode the AI also drafts a reply. A person sends it as is, edits it, or discards it.
3. **Replies** (`SendReply`). They go out on the customer's channel. Long replies are split at sentence boundaries to fit the channel (Instagram 1,000 characters, Messenger 2,000). When a team member replies, the AI steps back in that conversation until someone presses *Hand back to AI*.

### Meta's reply windows (enforced in code)

| Since the customer's last message | Team member | AI |
|---|---|---|
| Up to 24 hours | Normal reply | Normal reply |
| 24 hours to 7 days | Reply with Meta's `HUMAN_AGENT` tag (shown in the reply box) | Never |
| More than 7 days | Can't reply (the inbox explains why); a team note is still possible | Never |

Replies the business sends from Facebook's or Instagram's own inbox arrive as echoes. They show in our inbox and pause the AI. Echoes of our own replies are recognised, including one that arrives before we've saved its id, and are never stored twice.

## The AI assistant

**What it knows** (`BusinessBrain`):
- The business profile, address, service area and emergency instructions.
- Weekly hours, services with price labels, durations, required details and internal agent instructions.
- Knowledge base items marked *Can be shared* (it may quote these) or *Agents and your team* (guidance only, never quoted). It **never** sees *Your team only* items.
- The business's rules, approved guidelines, and the business's extra instructions.

**Caching:** this stable part is cached by the model provider. It never contains the time or customer data.

**Per message** it also gets:
- the current time in the business's timezone,
- whether the business is open, and special days and time away in the next three weeks,
- **only this customer's** name, which details are on file, and their upcoming appointments.

**What it can do:** tools only (`AssistantTools`), the same actions a human agent uses.

| Tool | Does | Offered in |
|---|---|---|
| `get_available_times` | Real free times (hours, rules, buffers, existing bookings, connected calendars) | suggest, auto |
| `search_knowledge` | Searches the knowledge base (not team-only items) | suggest, auto |
| `book_appointment` | Books under **strict** rules (opening hours, business rules, double-booking guard), source `ai`, confirmed or pending per settings. Needs a name and a phone number or email | auto, if booking is on |
| `save_customer_details` | Matches or creates the customer and links the conversation | auto |
| `create_follow_up` | Call-back or follow-up task for the team | auto |
| `hand_over_to_team` | Raises an escalation (complaint, refund, pricing, emergency → urgent, …) and stops replying | auto |

**Guardrails** (`ConversationAssistant`):
- It does nothing when switched off, without an API key, when a person has taken over, or outside Meta's 24-hour window.
- **Limits:** at most 6 tool rounds per reply, 20 AI replies per conversation per hour, and the plan's monthly `ai_replies` limit when set.
- **Hand-over:** a refusal, a reply cut short, too many steps or an API outage hands the conversation to the team.
- **Concurrency:** one reply at a time per conversation (lock); a newer customer message supersedes an older one.
- **Logging:** each run is logged in `ai_runs` with its tool calls, token use and status (replied, drafted, handed_over, skipped, failed).

**Customers are told** it's an AI assistant: in the website chat header and whenever they ask.

### Feedback and guidelines

- Anyone who can see the inbox can rate an AI reply.
- *Not helpful* asks "What should it have said or done?". The answer becomes a **draft guideline**.
- Drafts appear under *Inbox › AI assistant › Guidelines*. Someone with `ai.manage` edits and approves them, or dismisses them.
- Only **active** guidelines are added to the assistant's instructions. Guidelines added directly on that page are active straight away.

## One-time setup (SureHelp)

### Anthropic (AI)

1. Create an organisation in the [Anthropic Console](https://console.anthropic.com/), accept the commercial terms and data processing addendum, and add billing.
2. Create an API key and set it in `.env`:
   ```
   ANTHROPIC_API_KEY=sk-ant-...
   AI_MODEL=claude-opus-5-5   # default
   AI_EFFORT=medium           # low | medium | high
   ```
   `AI_FALLBACKS=true` (default) lets Anthropic retry a declined request on its default fallback model.
3. Until the key is set, the assistant page shows "Coming soon" and nothing is sent anywhere.

The code uses the official SDK (`anthropic-ai/sdk`) through `App\Services\Ai\Contracts\AiProvider`, so the vendor can be swapped.

### Website chat

Nothing to register. Each business copies its snippet from *Inbox › Channels*:

```html
<script src="https://YOUR-DOMAIN/api/chat/widget.js" data-surehelp-chat="shw_..." async></script>
```

How the widget's endpoints are protected:
- The endpoints (`/api/chat/{key}/…`) are public but rate limited: 20 requests a minute per visitor and 300 per widget.
- Each widget can be limited to its business's own websites.
- Visitors hold a random token; only its SHA-256 hash is stored.

### Messenger and Instagram

Use the same Meta app as social publishing ([social.md](social.md)), plus:

1. **Permissions:** add `pages_messaging`, `pages_manage_metadata` and `instagram_manage_messages` to App Review.
2. **Webhooks** (*Messenger* and *Instagram*):
   - callback URL `https://YOUR-DOMAIN/api/webhooks/meta`;
   - verify token: any long random string, also set as `META_WEBHOOK_VERIFY_TOKEN`;
   - subscribe to `messages` and `message_echoes`.
3. **Per business:** connect (or reconnect) Facebook & Instagram, then switch *Messages in my inbox* on per Page/account under *Inbox › Channels*. This subscribes our app to that Page.

Every webhook is checked against `X-Hub-Signature-256` (HMAC-SHA256 with `META_APP_SECRET`).

## Mobile API

`GET /api/v1/client/inbox/conversations`, `GET /api/v1/client/inbox/conversations/{id}`, `POST …/{id}/reply`. See [api.md](api.md).

## Later

- SMS (after A2P 10DLC), email, Facebook / Instagram comments, WhatsApp.
- Replying with photos.
- Typing indicators and real-time updates (after the hosting move, D6).
- AI call summaries (§37) using the same `AiProvider`.
