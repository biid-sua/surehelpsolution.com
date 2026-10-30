# Live calls (Twilio)

How calls to a business's SureHelp number reach the agents (decision D60).

## How a call goes

1. A caller dials the business's SureHelp number. Usually the business forwards its own phone there.
2. If the line records calls, the caller first hears: "Thanks for calling. This call may be recorded for quality and training."
3. Every available agent who serves the business hears it ring in their browser. The first to press **Answer** gets it.
   - That business's call screen opens in its own tab, with the caller's number filled in and the customer picked when the number is known.
4. During the call the agent can mute, open the call screen, **transfer to the owner** (they leave the call) or hang up.
5. After the call:
   - Saving the call entry links it to the recording and transcript.
   - The AI's summary is offered as notes. When the notes are left empty, it fills them by itself.
6. Nobody available or nobody answers within 20 seconds:
   - the call rings the owner's phone, if the line has one;
   - if the owner doesn't answer, the caller can leave a voicemail;
   - the business gets a high-priority **call-back task** and a "Missed call" or "Voicemail" notification.

## Agents

- The phone pill in the top bar of the agent workspace sets your status:
  - **Ready for calls**: calls ring.
  - **Away**: calls don't ring, but you stay signed in.
  - **Offline**.
- Calls only ring while a portal tab is open. If you close every tab, you go offline within about a minute.
- **Microphone:** allow it the first time (Chrome, Edge, Firefox or Safari). A headset is strongly recommended.
- **Finishing up:** after a call you have 2 minutes to finish the call entry before calls ring again. Saving the entry, or choosing "Ready for calls", ends it sooner.
- **Several tabs:** only one tab rings. The others show what's happening.

## Administrators: *Admin › Phone lines*

- **Add a line:**
  - Choose the business.
  - Then either search an area code and buy a number, or enter the SID (`PN…`) of a number already in the Twilio account.
  - Its call settings at Twilio are set for you.
- **Owner's phone:** used for transfers, and for calls nobody at SureHelp answers. Leave it empty to go straight to voicemail.
- **Record and transcribe:** on by default. Callers always hear the notice first. Check what the law requires where the business and its callers are. Some US states require everyone on the call to agree.
- **Release:** gives the number back to Twilio. The business must stop forwarding to it first.
- **Statistics:** calls, the share answered by agents, missed calls and voicemails, average wait and average talk time.
- **Recordings:** played from a call's page (business portal) or a quality review, by people with "listen to call recordings" (`calls.recording.view`): owners, managers and agent supervisors. Each play is recorded in the audit log.

## Without Twilio (demo mode)

> Keys and settings for this are entered under **Admin › Connections** (D105), not in `.env`. The card there lists the web addresses to paste into the service and has a Test button.

`TELEPHONY_DRIVER=fake` (the default):
- Lines get pretend 555 numbers.
- Agents start a call from **Your phone › Try a call to a business's line**.
- Everything else works the same, except audio, recordings and transcripts.

Use it for training, demos and testing.

## Going live: what you need to do

1. **Create a Twilio account** at twilio.com and upgrade it from trial (trial accounts only call verified numbers).
2. **Start A2P 10DLC registration now** (Messaging › Regulatory compliance). It is only needed for SMS, but approval takes weeks.
3. **Create an API key:** Account › API keys & tokens › Create API key (type "Standard"). Copy the SID (`SK…`) and the secret; the secret is shown only once.
4. **For agents calling out, create a TwiML App** (Voice › Manage › TwiML Apps › Create):
   - **Voice request URL:** `https://YOUR-DOMAIN/api/webhooks/voice/outgoing` (POST).
   - **Status callback URL:** `https://YOUR-DOMAIN/api/webhooks/voice/status`.

   Copy its SID (`AP…`) into `TWILIO_TWIML_APP_SID`. Without it, agents can take calls but not place them.
5. **Add to the server's `.env`:**

   ```
   TELEPHONY_DRIVER=twilio
   TWILIO_ACCOUNT_SID=AC...
   TWILIO_AUTH_TOKEN=...
   TWILIO_API_KEY=SK...
   TWILIO_API_SECRET=...
   TWILIO_TWIML_APP_SID=AP...
   # Only once the A2P 10DLC registration is approved (see "Texting" below):
   # TELEPHONY_SMS=true
   # Only if the public address differs from APP_URL (e.g. behind a proxy):
   # TWILIO_WEBHOOK_URL=https://surehelpsolution.com
   ```

   Then run `php artisan config:cache`.
6. **Run the migrations** (`php artisan migrate --force`). This adds `phone_numbers`, `voice_calls` and agent phone status.
7. **Check the public address:**
   - The site must be reachable over **https** at its public address. Twilio calls `https://…/api/webhooks/voice/…`.
   - Every request is checked against Twilio's signature, so the address Twilio uses must be exactly the one in `APP_URL` (or `TWILIO_WEBHOOK_URL`).
8. **Add the lines** under *Admin › Phone lines*.
9. **Ask each business to forward its phone**, either always or when busy/unanswered, depending on how they want to work.
10. **Live transcription and summaries** need Twilio's Real-Time Transcription, which is on by default for Voice, and the Anthropic key already used by the AI assistant. Turn transcription off with `TELEPHONY_TRANSCRIBE=false`.

## Transferring a caller to the owner

There are two ways, both from the call card while on a call:
- **Talk to owner first** (warm transfer):
  1. The caller hears "Please hold for a moment" and waits with hold music.
  2. Your phone calls the owner.
  3. Then either:
     - **Connect caller:** the owner and caller are connected, and you leave the call; or
     - **Back to caller:** if the owner can't take it, you return to the caller.
- **Transfer to owner** (cold transfer): the caller is sent straight to the owner's phone, and you leave the call. If the owner doesn't answer, the caller can leave a voicemail.

The recording covers your part of the call. The conversation between the owner and the caller after a warm transfer isn't recorded.

## When the business is closed

Each line chooses what happens outside the business's opening hours, holidays and closures (*Business › Hours*):
- **Our agents answer, as during the day** (the default).
- **Ring the owner's phone, then voicemail.**
- **Straight to voicemail.**

A business that hasn't set any opening hours is treated as always open. Calls that arrive while the business is closed are marked "after hours" in the call lists.

## Agents calling customers

- **Where:**
  - **Your phone › Call a customer:** pick the business and type the number.
  - The **Call** button next to the phone number on the call screen.
- **What the customer sees:** the business's SureHelp number.
- **Recording:** follows the line's setting.
- **The call log:** opening the call screen for that business links it to the call.
- **Limits:**
  - US and Canadian numbers only. Premium-rate numbers (900, 976) are blocked.
  - One call at a time.
  - Only for businesses the agent currently serves.
- **Who chooses the number:** the server records the number before the browser connects, and Twilio may place only that call, once. The browser can't choose who to call.
- **Statistics:** calls out are listed, but they don't count towards the answer rate.

## The business's own page: *Business › Phone line*

Owners and managers see and change:
- the SureHelp number;
- how to forward their phone to it (common carrier codes);
- the last 30 days of calls and the answer rate;
- recent calls, with links to each call log.

They can also set:
- their own phone, for transfers and missed calls;
- what happens when they're closed;
- whether calls are recorded;
- missed-call texts.

Staff without settings access can't open the Business section.

## Texting

- **Texts to the SureHelp number** arrive in the business's **Inbox** as "Text message" conversations, matched to the customer by phone number.
- **Replies go back as texts from that number**, whether written by staff or by the AI assistant (set the assistant's mode for texts under *Inbox › AI assistant*).
- **STOP:** someone who texts STOP (or UNSUBSCRIBE, CANCEL, END, QUIT…) is never texted by that business again, and their customer record's texting consent is withdrawn.
- **START:** texting START allows texts again.
- **What Twilio does:** it answers STOP, START and HELP itself.
- **Missed-call text-back** (per line, off by default):
  - Anyone whose call nobody could answer gets a text, at most once in 12 hours. The default is "Sorry we missed your call to {business}…".
  - Their reply continues the same Inbox conversation, next to the call-back task.
- **Appointment texts:** customers who agreed to texts (the customer's "texting consent") also get a short text of the booking confirmation, reminder, change or cancellation, next to the email. Customers without consent get email only.
- **Delivery failures** show on the message, with the reason in plain words.
- **Pictures** (MMS): shown in the conversation, up to 5 per text and 5 MB each (JPEG, PNG, GIF, WebP, HEIC).
  - They are copied into the app's private storage and shown only to people who can read that business's Inbox.
  - Other files get a note saying they couldn't be shown.
  - Sending pictures isn't possible yet.
- **Starting a conversation:** **New text** in the Inbox, or **Text** on a customer's page.
  - **Who can be texted:** only people who agreed to texts (their customer record says so) or who texted the business before.
  - Never someone who texted STOP.
- **The business's own wording:** each appointment message under *Business › Customer emails* has a "Text message" box next to the email. Leave it empty to use the default.

### Turning texting on (US): A2P 10DLC

US carriers block business texts from local numbers until the business is registered, so texting stays **off** with Twilio until you set `TELEPHONY_SMS=true`. The demo phone always "texts", but nothing is sent.

1. In Twilio: **Messaging › Regulatory compliance**.
   - Register your **brand** (your company: legal name, EIN, address and website).
   - Then register a **campaign**: use case "Customer care" or "Mixed", with sample messages (use the texts above) and how customers agree (for example, "customers ask us to text them, or text us first").
   - Approval usually takes **1–3 weeks**.
2. Create a **Messaging Service**:
   - add the SureHelp numbers to it;
   - attach the approved campaign;
   - set its incoming messages URL to `https://YOUR-DOMAIN/api/webhooks/sms/incoming`.

   Numbers added under *Admin › Phone lines* already point their texts there.
3. Set `TELEPHONY_SMS=true`, then run `php artisan config:cache`.
4. Send a test text to a line and reply from the Inbox.

## Technical notes

- **Webhooks** live in `routes/api.php` under `webhooks/voice/` and `webhooks/sms/`. They are signed and throttled.
  - `incoming` and `status` are set on each number.
  - The other webhooks are given to Twilio per call: agent leg, dial finished, owner finished, transfer, voicemail, recording and transcription.
- **Code:** `app/Services/Telephony/`:
  - `CallRouter`: where a call goes, as TwiML;
  - `VoiceCalls`: what happens to it;
  - `AgentPresence`: who can take a call;
  - `VoiceStats`: the statistics;
  - `Texting`: texts, opt-outs and the gate that keeps texting off until registration is approved.
- **Queued jobs:**
  - `FinishVoiceCall`: the missed-call task and notification;
  - `SummarizeVoiceCall`: the AI summary.

  Both run on the queue worker that already runs from cron.
- **Tests:** `tests/Feature/TelephonyTest.php`.
