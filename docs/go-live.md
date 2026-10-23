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
3. Fill in the production `.env`. The table in deployment.md covers the core settings, and
   `launch:check` names every missing one.
4. Run `php artisan launch:check` until there are **no ✗ items**.
5. Send a test email: `php artisan launch:check --mail=you@yourdomain.com`.
6. Once: `php artisan training:starter`, then review and publish the starter courses in Agent University.

## Week 1: configure in the admin console

1. **Billing:** *Admin › Billing › Payment settings* (company details, how clients pay), then *Plans*. Keep the
   prices in step with the website and `config/sales.php`. See [billing.md](billing.md).
2. **Feature access:** decide which plans include which features (*Admin › Billing › Feature access*).
3. **Agents:** review *Agent assignments*, so each agent serves only their businesses.
4. **AI:** set `ANTHROPIC_API_KEY`.
   - For the website chat, set `SALES_CHAT_MODEL=claude-haiku-5-5` and the demo hours (`SALES_DEMO_*`).
   - The token budgets have safe defaults (`SEO_MONTHLY_TOKENS`, `SOCIAL_AI_MONTHLY_TOKENS`, `SALES_CHAT_DAILY_TOKENS`).

## As each approval arrives

| Approval | Then |
|---|---|
| Google OAuth client | `GOOGLE_CALENDAR_CLIENT_ID` / `_SECRET`. Connect one business's calendar and check that a booking appears in it |
| Google Business Profile API | `GOOGLE_BUSINESS_ENABLED=true` |
| Microsoft app | `MICROSOFT_CALENDAR_CLIENT_ID` / `_SECRET` / `_TENANT` |
| Twilio account (calls) | `TELEPHONY_DRIVER=twilio` and the `TWILIO_*` keys. Create the TwiML App with the addresses from `--urls`, buy a line in *Admin › Phone lines*, then call it |
| A2P 10DLC (texts) | Create the Messaging Service with the approved campaign, then `TELEPHONY_SMS=true` and `php artisan config:cache`. Text a line and reply from the Inbox |
| Meta App Review | `META_APP_ID` / `_SECRET` / `META_WEBHOOK_VERIFY_TOKEN`. Connect a page and send it a Messenger message |
| LinkedIn | `LINKEDIN_CLIENT_ID` / `_SECRET` |
| Stripe Connect (optional, D85) | Turn on Connect (Standard accounts) in the Stripe dashboard, add the Connect webhook from `--urls` and set `STRIPE_CONNECT_WEBHOOK_SECRET`. Optionally set `STRIPE_CONNECT_FEE_PERCENT`. Businesses then connect under *Payments* |
| Stripe (optional) | `BILLING_GATEWAY=stripe`, `STRIPE_SECRET`, plus the webhook endpoint from `--urls` and its `STRIPE_WEBHOOK_SECRET` ([billing.md](billing.md) › Stripe) |
| Firebase (push) | `FCM_PROJECT_ID`, `FCM_CREDENTIALS` (service-account JSON) and the APNs key uploaded for iOS |
| Store listings live | `MOBILE_IOS_STORE_URL`, `MOBILE_ANDROID_STORE_URL`, `MOBILE_ANDROID_SHA256` and `MOBILE_IOS_APP_IDS` (app links). Then open the two `.well-known` addresses from `--urls` in a browser to check them |

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

## After launch

- Watch *Admin › System status* daily for the first week (failed jobs, the scheduler).
- After a week with `CSP_MODE=report` and no real violations, keep `CSP_MODE=enforce`.
- Microsoft client secrets expire (6–24 months). Put the date in your calendar.
