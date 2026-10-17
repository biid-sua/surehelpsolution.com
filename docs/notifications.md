# Notification texts and phone push

How people get SureHelp notifications by text message and on their phones (decision D63). In-app and email notifications work as before.

## For everyone

- **Where to choose:**
  - business people: *Settings › Notifications* in the business portal;
  - SureHelp staff and agents: *Your account › Notifications*.
- **Texts:**
  1. Enter your mobile number under "Texts to your mobile".
  2. Type back the 6-digit code we text you.
  3. Tick **Text message** for the notifications you want.

  - A code lasts 10 minutes and allows 5 tries. You can ask for at most 3 codes in 10 minutes.
  - Replying STOP to a SureHelp text stops notification texts until you verify the number again.
- **Push:**
  - Install the SureHelp app and sign in.
  - Push is on by default for every notification that is also on by default.
  - Under "Phone app notifications" you can see your signed-in phones and remove any of them.
- **Quiet hours** hold emails, texts and push until morning. In-app notifications still arrive. Urgent escalations always come through, including by push.

## What a text looks like

> SureHelp: Missed call from Maria Lopez. Maria Lopez ((512) 555-0147) called your SureHelp line. We added a call-back task for you. https://surehelpsolution.com/app/tasks

At most 320 characters: the title, one line of detail, and a link. Texts come from SureHelp's own number, never from a business's line.

## Setting it up

### Texts
1. Buy one more Twilio number for SureHelp itself, for example under *Admin › Phone lines*, or directly in Twilio.
2. Add it to the same A2P 10DLC registration (Messaging Service) as the business lines. The use case is "account notifications".
3. Point its incoming text URL at `https://YOUR-DOMAIN/api/webhooks/sms/incoming`, so a STOP reply is seen.
4. In `.env`, set `TELEPHONY_NOTIFY_FROM=+1…` (with `TELEPHONY_SMS=true`, as for business texting), then run `php artisan config:cache`.

### Push (Firebase Cloud Messaging HTTP v1)
Google switched off the old "server key" push API in 2024. The app now uses HTTP v1 with a service account.
1. In the Firebase console, open the app's project and go to **Project settings › Service accounts › Generate new private key**. This downloads a JSON file.
2. Put the file on the server outside the web root, for example `storage/app/private/firebase.json`. Then set `FCM_CREDENTIALS=/full/path/to/firebase.json` in `.env`. The variable also accepts the JSON itself, or the JSON in base64. `FCM_PROJECT_ID` is optional and is read from the file.
3. Remove the old `FCM_SERVER_KEY` and `FCM_SENDER_ID`, then run `php artisan config:cache`.

### The mobile app
The app must:
- register its token after signing in: `POST /api/v1/notifications/device-token` with `token`, `platform` and `device_name`;
- remove it when signing out: `DELETE /api/v1/notifications/device-token` with `token`;
- open `data.url` when a notification is tapped. `data.event` names the kind of notification.

Devices Firebase no longer knows are removed automatically.

## Technical notes
- **Channels:** `sms` and `push` are Laravel notification channels (`app/Notifications/Channels`). Every notification that follows people's choices can use them, with no change to the notification itself, because the short form comes from its in-app payload (title, body, url).
- **When a channel counts as available:** `App\Support\NotificationChannels`.
  - Texts need `TELEPHONY_NOTIFY_FROM` and texting switched on.
  - Push needs the service account.
  - A channel that isn't available shows as "Not available yet" in settings and is never used.
- **Push service:** `App\Services\FcmService`.
  - It signs in with a JWT (RS256), and the access token is cached until five minutes before it expires.
  - It sends to one device at a time.
  - Its logs hold counts only, never content or tokens.
