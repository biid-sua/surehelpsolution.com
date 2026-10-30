# Go-live checklist (D79)

Everything merged since the 4 October release is built and tested, but not live. This is the order to
switch it on.

Two tools help at every step:
- `php artisan launch:check` (or **Admin › System status**) shows what's ready and what's missing.
- `php artisan launch:check --urls` lists every address to paste into Google, Microsoft, Meta, LinkedIn,
  Twilio, Stripe and the app stores.

Steps marked 🕒 wait on someone else's approval. Start them first: they take days or weeks.

## Week 0: start the slow approvals (today)

1. 🕒 **Texting approval: Twilio A2P 10DLC, 1–3 weeks.**
   - Create the Twilio account, upgrade from trial, then register the **brand** and a **campaign** under
     *Messaging › Regulatory compliance* ([telephony.md](telephony.md) › Turning texting on).
   - Calls work without it; texts don't.
2. 🕒 **Google, 1–6 weeks.** In Google Cloud, create one project and an OAuth client (web application):
   - Add the redirect URIs from `launch:check --urls` (calendar, Business Profile, Search Console).
   - The calendar and Business Profile scopes are "sensitive", so submit the **OAuth consent screen for
     verification** (privacy policy URL, a short video of the sign-in). Until it's verified, at most
     100 test users can connect.
   - Request **Business Profile API** access for the project. It's a separate form, often weeks.
3. 🕒 **Meta (Facebook, Instagram, Messenger), 1–4 weeks.**
   - Create a Business app with Facebook Login and Messenger.
   - Add the redirect URI and the webhook, using any long random string as `META_WEBHOOK_VERIFY_TOKEN`.
   - Submit **App Review** for the page, Instagram and messaging permissions ([social.md](social.md), [inbox.md](inbox.md)).
4. 🕒 **App stores, 1–3 weeks.**
   - Apple Developer Program ($99/year) and a Google Play developer account ($25).
   - New Google Play personal accounts must run a **closed test with 12 testers for 14 days** before
     production. Start that test with the APK/AAB from the app's *Build APK* workflow.
   - The store steps are in the app repo's README.
5. **Microsoft (Outlook / Microsoft 365), same day.** Register an app in Microsoft Entra, add the redirect
   URIs, and create a client secret. Note when it expires.
6. **LinkedIn, a few days.** Create an app and request *Community Management* access.

## Week 1: prepare the server

1. **Back up** the database and files.
2. **PHP 8.3** with the `zip` extension. Upload limits must be at least `TRAINING_MAX_UPLOAD_MB` (50).
3. **The cron entry**, which runs the queue, reminders, billing, calendar sync and everything else:
   ```
   * * * * * cd /home/<account>/<path> && php artisan schedule:run >> /dev/null 2>&1
   ```
4. **Email**:
   - Choose a sender: SMTP, Postmark, Resend or Amazon SES.
   - Set up **SPF, DKIM and DMARC** for your domain, then set `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`
     and `MAIL_CONTACT_TO`.
   - Without this, invoices, reminders and sign-in emails land in spam.
5. **HTTPS** on the live domain. `APP_URL` must be the exact `https://` address, because webhooks are signed
   against it.

## Week 1: deploy

Follow [deployment.md](deployment.md) › Every release:
1. Upload the code, `vendor/` and `public/build/`.
2. Run `php artisan migrate --force`, `php artisan optimize:clear` and `php artisan optimize`.
3. Fill in the production `.env` with the server settings only (app key and address, database,
   cache, sessions). The table in deployment.md covers them. Every outside service is set up in
   the admin console instead (below), and `launch:check` names anything missing.
4. Run `php artisan launch:check` until there are **no ✗ items**.
5. Set up email under *Admin › Connections › Email* and press **Send me a test email**.
6. Once: `php artisan training:starter`, then review and publish the starter courses in Agent University.

## Week 1: configure in the admin console

1. **Billing:** *Admin › Billing › Payment settings* (company details, how clients pay), then *Plans*. Keep the
   prices in step with the website and `config/sales.php`. See [billing.md](billing.md).
2. **Feature access:** decide which plans include which features (*Admin › Billing › Feature access*).
3. **Agents:** review *Agent assignments*, so each agent serves only their businesses.
4. **AI:** add a Claude or ChatGPT key under *Admin › Connections › AI* and press **Test**.
   - For the website chat, set `SALES_CHAT_MODEL=claude-haiku-5-5` and the demo hours (`SALES_DEMO_*`).
   - The token budgets have safe defaults (`SEO_MONTHLY_TOKENS`, `SOCIAL_AI_MONTHLY_TOKENS`, `SALES_CHAT_DAILY_TOKENS`).

## As each approval arrives

Everything here is entered under **Admin › Connections** (D105). Each card says where to find the
keys and lists the web addresses to paste into the service. Press **Test** after saving where
there is one. Saved values override `.env`, so old `.env` entries can stay as a fallback.

| Approval | Then (Admin › Connections) |
|---|---|
| Google OAuth client | *Calendars › Google Calendar*: client ID and secret. Connect one business's calendar and check that a booking appears in it |
| Google Business Profile API | *Social networks › Google Business Profile*: tick "Business Profile API access granted" |
| Microsoft app | *Calendars › Microsoft Outlook calendar*: client ID, secret and which accounts |
| Twilio account (calls) | *Phone and texts*: the account, API key and TwiML App (with the voice URL shown there), and "Calls go through: Twilio". Press **Test**, buy a line in *Admin › Phone lines*, then call it |
| A2P 10DLC (texts) | Create the Messaging Service with the approved campaign, then tick "Texting is on" under *Phone and texts*. Text a line and reply from the Inbox |
| Meta App Review | *Social networks › Facebook and Instagram*: app ID, secret and webhook verify token. Connect a page and send it a Messenger message |
| LinkedIn | *Social networks › LinkedIn*: client ID and secret |
| Stripe (optional) | *Payments*: secret key, the webhook signing secret for the endpoint shown there, and "Stripe" for how businesses pay ([billing.md](billing.md) › Stripe) |
| Stripe Connect (optional, D85) | Turn on Connect (Standard accounts) in Stripe, add the Connect webhook shown under *Payments* and its signing secret, and optionally SureHelp's share. Businesses then connect under *Payments* |
| Firebase (push) | *Mobile app*: paste the service account key, upload the APNs key for iOS in Firebase, and press **Test the saved key** |
| Store listings live | *Mobile app › App stores and versions*: both store links, the Android SHA-256 and iOS app IDs. Then open the two `.well-known` addresses from `--urls` in a browser to check them |

## Launch-day smoke test (about 30 minutes)

1. **As a new business:** sign up at `/start` and finish the setup wizard.
2. **As an agent:** take a call (or use the demo call), log it, and book an appointment.
3. **As the business:**
   - see the call and the booking on the dashboard;
   - get the confirmation email;
   - reply to a website chat message in the Inbox.
4. **As a visitor:** open the website chat, book a demo, and find the lead in *Admin › Website enquiries*.
5. **As an admin:** issue an invoice and record a payment.
6. **On a phone:** install the app, sign in, and receive a push notification.
7. Finally, `php artisan launch:check` should show no ✗ items, and *System status* should show the
   scheduler as running.

## Operations (D91)

- **Error alerts:** set `OPS_ALERT_EMAIL`. Each server error is emailed, the same one at most every
  15 minutes and at most 20 an hour, with no personal data.
- **Uptime:** point an uptime monitor (UptimeRobot, Better Stack…) at `https://<your site>/health`.
  - It answers 200, or 503 when the database, cache, scheduler, queue or disk space has a problem.
  - Set `HEALTH_TOKEN` to see each check at `/health?token=…`.
- **Backups:** `php artisan backup:database` runs every night at 03:15. It keeps 14 days in
  `storage/app/backups`.
  - Set `BACKUP_DISK=s3` (with the AWS settings) for a copy off the server.
  - Restore a copy on a test database once, to be sure it works.

## After launch

- Watch *Admin › System status* daily for the first week (failed jobs, the scheduler).
- After a week with `CSP_MODE=report` and no real violations, keep `CSP_MODE=enforce`.
- Microsoft client secrets expire (6–24 months). Put the date in your calendar.
