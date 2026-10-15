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

`TELEPHONY_DRIVER=fake` (the default):
- Lines get pretend 555 numbers.
- Agents start a call from **Your phone › Try a call to a business's line**.
- Everything else works the same, except audio, recordings and transcripts.

Use it for training, demos and testing.

## Going live: what you need to do

1. **Create a Twilio account** at twilio.com and upgrade it from trial (trial accounts only call verified numbers).
2. **Start A2P 10DLC registration now** (Messaging › Regulatory compliance). It is only needed for SMS, but approval takes weeks.
3. **Create an API key:** Account › API keys & tokens › Create API key (type "Standard"). Copy the SID (`SK…`) and the secret; the secret is shown only once.
4. **Optional:** a TwiML App (Voice › TwiML Apps) is only needed later, for agents calling out.
5. **Add to the server's `.env`:**

   ```
   TELEPHONY_DRIVER=twilio
   TWILIO_ACCOUNT_SID=AC...
   TWILIO_AUTH_TOKEN=...
   TWILIO_API_KEY=SK...
   TWILIO_API_SECRET=...
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

## Technical notes

- **Webhooks** live in `routes/api.php` under `webhooks/voice/`. They are signed and throttled.
  - `incoming` and `status` are set on each number.
  - The other webhooks are given to Twilio per call: agent leg, dial finished, owner finished, transfer, voicemail, recording and transcription.
- **Code:** `app/Services/Telephony/`:
  - `CallRouter`: where a call goes, as TwiML;
  - `VoiceCalls`: what happens to it;
  - `AgentPresence`: who can take a call;
  - `VoiceStats`: the statistics.
- **Queued jobs:**
  - `FinishVoiceCall`: the missed-call task and notification;
  - `SummarizeVoiceCall`: the AI summary.

  Both run on the queue worker that already runs from cron.
- **Tests:** `tests/Feature/TelephonyTest.php`.
