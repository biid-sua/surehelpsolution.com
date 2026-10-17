# SureHelp mobile app: modernisation task list

**For:** the Claude session working in `biid-sua/SHS_Mobile_App` (Flutter).
**Written from:** the web system (`biid-sua/surehelpsolution.com`), as of decision D69.
**Goal:** bring the app up to date with the web system and make it fast, reliable and pleasant for **business owners (clients)** and **agents**. Admin features stay light: staff work on the web.

Tasks are numbered **M-…** (mobile repo) and **API-…** (web repo, built by the web-repo session). A mobile task that needs a new endpoint names it, e.g. *needs API-3*. Do the phases in order; tasks inside a phase can run in parallel.

---

## 0. What the app looks like today (audit, Oct 2026)

- **Stack:**
  - Flutter, Dart SDK ^3.9.2;
  - packages: `http`, `shared_preferences`, `firebase_core` / `firebase_messaging` and `flutter_local_notifications`;
  - no state management, no router, no models layer;
  - about 5,800 lines in `lib/`, one commit ("Initial commit").
- **Screens:**
  - working: splash, login, a dashboard per role (`dashboard_screen.dart`, 1,200 lines), calendar (duty schedules and client calendar), call history, notifications (stored locally) and a detail screen;
  - placeholders ("coming soon"): Messages, Users, Analytics, Settings, Help and About.
- **Roles:** the role from the login response is saved in SharedPreferences, and `if (_userRole == 'client')…` branches inside one dashboard.
- **API calls:** `lib/services/shs_api_service.dart` uses base URL `https://surehelpsolution.com/api/v1`, hard-coded in two files.
  - **Used:** `/login`, `/user`, `/refresh-token`, `/logout`, `/client/dashboard/summary`, `/client/call-history`, `/client/service-requests`, `/client/profile`, `/client/calendar`, `/agent/dashboard/kpi`, `/agent/dashboard/performance`, `/agent/call-logs`, `/agent/clients`, `/admin/*` (stats, analytics, agent performance, users, duty schedules), `/duty-schedules` and `/notifications/device-token`.
  - **Missing:** `/verify-token`, called in `auth_service.dart`, doesn't exist on the server.
- **Problems to fix first:**
  1. **The token is printed to the log** (`shs_api_service.dart:11-13`) and kept in plain SharedPreferences (`login_screen.dart:55`).
  2. **The login ignores** two-step sign-in, `device_name`, `expires_at`, `two_factor_setup_required`, `email_verification_required` and `account_deactivated`. So agents and staff (two-step sign-in is mandatory for them) **can't sign in at all**.
  3. **No token refresh before the 30-day expiry**, and no global handling of `401`.
  4. **On logout the push token isn't removed** (`DELETE /notifications/device-token` exists).
  5. **Push taps don't open the right screen:** the server sends `data.url` and `data.event`.
  6. **No tests** beyond the default `widget_test.dart`, no CI, no crash reporting, no dark mode, no localisation.
  7. **Desktop and web folders** (`linux/`, `macos/`, `windows/`, `web/`) are unused.
- **App IDs:** Android `surehelpsolution.com.shs_mobile_app`, iOS `surehelpsolution.com.shsMobileApp`, version `1.0.0+1`, iOS 13.0 minimum.

## 1. The server contract (read before coding)

Source: `docs/api.md` in the web repo. That file is authoritative; ask for it if you can't see it.

- **Envelope:** `{ "success": true, "message": "...", "data": {...} }`. Errors: `{ "success": false, "message": "...", "errors": { field: [..] } }`. `message` is always safe to show to people.
- **Status codes:**
  - `401`: signed out or token expired;
  - `403`: not allowed (`account_deactivated: true` means the account is switched off);
  - `404`: not found, or another business's record (treat both the same);
  - `409`: conflict;
  - `422`: validation (show the field errors);
  - `429`: rate limited (`retry_after` seconds plus a `Retry-After` header);
  - `500`: our fault.
- **Sign-in:**
  - `POST /login` with `email`, `password`, `device_name` (e.g. "Ana's iPhone 16"), and `two_factor_code` when asked.
  - Possible answers:
    - `401` with `two_factor_required: true`: show the code field and send the login again with the code;
    - `403` with `two_factor_setup_required: true`: the person must set up two-step sign-in on the website first;
    - `403` with `email_verification_required: true`: a self-signed-up business owner must click the link in their email first;
    - `403` with `account_deactivated: true`.
- **Tokens:** Sanctum, valid 30 days. `data.expires_at` is returned. `POST /refresh-token` returns a new token and revokes the old one.
- **Limits:** login allows 5 per minute; everything else allows 120 per minute.
- **Retries:** `POST /agent/call-logs`, `/client/appointments`, `/client/tasks` and `/client/inbox/conversations/{id}/reply` accept an `Idempotency-Key` header. Send a new UUID per item created, and **the same key when retrying**.
- **Tenancy:** the server decides the business; never send an organization ID.
- **Push (FCM HTTP v1):**
  - `notification.title` and `notification.body`, plus `data` with every value a string, including `url` (a web address to open) and `event` (e.g. `call.logged`, `escalation.raised`, `appointment.booked`) and the notification's own fields.
  - Android channel id: `default-channel`, unless the server's `FCM_ANDROID_CHANNEL_ID` says otherwise; sound `default`.
  - **The app must use the same Firebase project as the server's service account**, or pushes won't arrive.

---

## Phase 1: Foundations (do first; everything else builds on it)

| ID | Task | Done when |
|---|---|---|
| M-1 | **Project hygiene:** remove the `linux/`, `macos/`, `windows/` and `web/` targets. Upgrade Flutter to the current stable and all packages to current majors (`firebase_messaging` 15 → latest, `flutter_local_notifications` 17 → latest). Set Android `targetSdk` 35 and `minSdk` 24, and iOS 15. Rename the package description; set the display name to "SureHelp". | `flutter analyze` is clean; both platforms build |
| M-2 | **Architecture:** feature-first folders (`lib/core`, `lib/features/<feature>/{data,domain,presentation}`). **Riverpod** for state and dependency injection. **go_router** with typed routes and a redirect guard driven by auth state. Split `dashboard_screen.dart` into per-role shells. | No screen calls HTTP directly; routes are declared in one place |
| M-3 | **API client:** `dio` with interceptors for the base URL per flavour, `Accept: application/json`, `Authorization` and `X-Device`. Parse the envelope into `Result<T>` / `ApiFailure` types (one per status code above, carrying `message`, field `errors` and `retry_after`). Add retries with backoff for timeouts and `429` (honouring `Retry-After`), `Idempotency-Key` on the creating calls, and request cancellation when a screen closes. | One place maps every server status to a type; unit-tested |
| M-4 | **Models:** `freezed` + `json_serializable` for every resource the app uses (user, business, appointment, customer, call, task, escalation, conversation/message, social post, course/lesson, device). Dates are ISO strings → `DateTime` in the business's timezone (the `timezone` package). | No `Map<String, dynamic>` reaches a widget |
| M-5 | **Flavours:** `dev` (local server), `staging`, `prod`, through `--dart-define` / `flutter_flavorizr`, with a separate app id suffix and Firebase config per flavour. No URL hard-coded in Dart files. | You can run dev and prod side by side on one phone |
| M-6 | **Secure storage:** move the token to `flutter_secure_storage` (Keychain / Keystore). Keep only non-secret preferences in SharedPreferences. **Delete every `print` of the token** and add a log wrapper that never logs headers or bodies in release. | grep finds no `print(` in `lib/`; the token isn't in SharedPreferences |
| M-7 | **Design system:** a theme from tokens (colour, type, spacing, radius), light and dark following the system setting, and the 10 portal colour themes (`GET /themes`, `PUT /user/theme`, D59). Shared widgets: buttons, inputs, cards, list tiles, empty, error and loading (skeleton) states, toasts, bottom sheets, a status badge. | A widget gallery screen in dev builds shows every component in both modes |
| M-8 | **Quality tooling:** stricter lints (`very_good_analysis` or similar). GitHub Actions running format, analyze, tests and a build of both apps on every PR. Firebase **Crashlytics**, plus Analytics with no personal data. | CI is green and required on `main` |
| M-9 | **Localisation scaffolding:** `flutter_localizations` + ARB files, English first, every user-facing string in ARB. Dates, times and money use the business's locale and timezone. | No string literals in widgets (a lint rule) |

## Phase 2: Sign-in, account and push

| ID | Task | Done when |
|---|---|---|
| M-10 | **Login flow per section 1:**<br>• `device_name` from `device_info_plus` ("Ana's iPhone 16");<br>• two-step code step, accepting a 6-digit code or recovery code;<br>• friendly screens for `two_factor_setup_required` (button opens `/account/security` on the web), `email_verification_required` (resend link opens the web) and `account_deactivated`;<br>• "Forgot password?" opens `/forgot-password` in the browser;<br>• "New to SureHelp? Start a free trial" opens `/start`. | Agents and staff with two-step sign-in can sign in; tests cover each answer |
| M-11 | **Session:**<br>• store `expires_at` and refresh silently when under 5 days are left (on launch and resume);<br>• any `401` clears the session and returns to login with "Please sign in again";<br>• remove the call to the non-existent `/verify-token` (use `GET /user`). | A 31-day-old token never shows an error loop |
| M-12 | **Biometric unlock** (optional per person): Face ID or fingerprint to open the app after 5 minutes in the background, using `local_auth`. The token stays in secure storage. | Setting in Account |
| M-13 | **Logout:** `DELETE /notifications/device-token` with the current FCM token, then `POST /logout`, then wipe secure storage and caches. | Signing out on a phone stops pushes to it |
| M-14 | **Devices:** Account › Devices lists `GET /devices` (this one marked), with sign out per device (`DELETE /devices/{id}`) and "sign out all other devices" (`DELETE /devices`). | Matches the web's device list |
| M-15 | **Push setup:**<br>• request permission at a sensible moment (after login, with an explanation screen; Android 13 `POST_NOTIFICATIONS`);<br>• register the token after login and on `onTokenRefresh` (`POST /notifications/device-token`);<br>• create the Android channel `default-channel`;<br>• iOS APNs key in Firebase, with background modes and the notification service extension if images are needed. | A test push (`POST /notifications/test`) arrives in foreground, background and killed states |
| M-16 | **Push taps open the right screen:** map `data.url` (a web path such as `/app/appointments/{ulid}`, `/app/inbox/{ulid}`, `/app/escalations/...`, `/agent/...`) to app routes with go_router. Unknown paths open the in-app notifications list. Also handle `getInitialMessage` (app opened from killed). | Every notification type in API-2 opens its record |
| M-17 | **Notification centre:** an in-app list with read/unread, mark all read and swipe to dismiss, replacing the locally stored list. The badge count goes on the app icon. *Needs API-2.* | Same items as the web bell |
| M-18 | **Notification settings:** per event, choose in-app / email / text / push, plus quiet hours and the verified mobile number for texts (send a code, confirm). *Needs API-3.* | Changes show on the web settings page |
| M-19 | **Account deletion inside the app** (required by Apple App Store rule 5.1.1(v) and Google Play): business owners request closure of the business (30-day grace, D29); agents and staff see how to ask. *Needs API-4.* | Reviewers can find it under Account |

## Phase 3: Business owners (clients)

The web portal's sections map to these screens. A bottom navigation bar holds **Home · Inbox · Calendar · Customers · More**.

| ID | Task | Endpoints | Done when |
|---|---|---|---|
| M-20 | **Home:** today's numbers (calls answered, bookings, new leads, missed calls), what needs attention (escalations, approvals, unanswered messages, overdue tasks), recent calls, and a period switch. | `GET /client/dashboard/summary`, `/client/call-history`, `/client/escalations`, `/client/social/posts?status=in_review` | Loads in under 1 s from cache, then refreshes |
| M-21 | **Calls:** a searchable list with filters (outcome, date); a call screen with caller, reason, notes, outcome, recording (signed URL) and transcript/AI summary when present; call back with one tap. | `/client/call-history` (+ API-6 for details, recording and transcript) | |
| M-22 | **Inbox:** conversations from website chat, Facebook, Instagram and text, with unread markers. A thread screen with replies (idempotent), pictures in messages, assignment and closing. Pushes for new messages. | `/client/inbox/conversations`, `/{ulid}`, `/{ulid}/reply` (+ API-7 for assign, close, new text, attachments) | Replying offline queues the reply and sends it once |
| M-23 | **Calendar and appointments:**<br>• day, week and list views with the business's colours;<br>• appointment details, then change the time (free times from `/client/availability`), cancel, mark completed or no-show;<br>• book a new appointment: service, customer search or new customer, time slot, location; idempotent;<br>• "add to device calendar". | `/client/appointments` (GET, POST, GET `{ulid}`, PATCH), `/client/availability`, `/client/services`, `/client/calendar` | Double-booking errors (`409`/`422`) show clearly |
| M-24 | **Customers:** search, details with a timeline (calls, appointments, messages, tasks), and tap to call, text or email. *Create and edit need API-8.* | `/client/customers`, `/{ulid}` | |
| M-25 | **Tasks and escalations:**<br>• tasks: list, create (idempotent), complete, edit due date;<br>• escalations: acknowledge, assign and resolve, with an urgent-banner style. | `/client/tasks*`, `/client/escalations*` | An urgent escalation push opens straight on it |
| M-26 | **Social posts:**<br>• the calendar and list with *AI draft*, *Written by AI* and *AI-assisted* labels (`ai_label`);<br>• approve or request changes on posts waiting for the owner;<br>• *creating and editing posts, Write with AI and last-minute openings need API-9*. | `/client/social/posts`, `/{ulid}/approve`, `/{ulid}/request-changes` | Approving from the push takes two taps |
| M-27 | **Results:** this month vs last; calls answered, jobs booked, estimated revenue; where customers came from (D68); share the PDF. *Needs API-10.* | API-10 | |
| M-28 | **Business info (read-only first):** profile, hours, services, knowledge, rules; link to edit on the web. | `/client/business`, `/client/services`, `/client/knowledge`, `/client/rules`, `/client/profile` (PUT for profile fields) | |
| M-29 | **Billing:** invoices, the balance, and pay by card (opens Stripe Checkout in an in-app browser and returns to the app); turn automatic payments on or off. *Needs API-11.* | API-11 | |
| M-30 | **Team:** members and roles, invite by email, remove. *Needs API-12.* | API-12 | |
| M-31 | **Link in bio and sharing:** show and copy the link-in-bio address (D69), and share it to Instagram or Facebook. *Needs API-9.* | API-9 | |

## Phase 4: Agents

Agents use the app on the go. Live call handling stays on the web softphone for now (see M-45).

| ID | Task | Endpoints | Done when |
|---|---|---|---|
| M-32 | **Home:** my numbers (`/agent/dashboard/kpi`, `/performance`), my next shift, businesses I serve, training due. | as listed | |
| M-33 | **Businesses I serve:** list and details (instructions, services, hours, rules), their recent calls, appointments, conversations and customers. | `/agent/companies`, `/{company}`, `/{company}/calls`, `/appointments`, `/conversations`, `/customers`, `/customers/{customer}` | Only assigned businesses ever show (`404` otherwise) |
| M-34 | **Log a call:** a fast form (business, caller, reason, outcome, notes, follow-up), with offline queue and `Idempotency-Key`; edit my own recent logs. | `POST/PUT /agent/call-logs` | A log made in a tunnel is sent once when back online |
| M-35 | **My schedule:** shifts calendar; request a swap or time off, and see the decision. *Requests need API-13.* | `/duty-schedules`, `/duty-schedules/calendar` (+ API-13) | |
| M-36 | **Agent University:** courses, lessons (video, PDF, text), quizzes with attempts, completion, certificates, and offline download of lesson files. | `/agent/training*` | Progress matches the web |
| M-37 | **Quality feedback:** my QA reviews, with read and confirm. *Needs API-14.* | API-14 | |
| M-38 | **Availability status** (available, on break, offline), the same as the web softphone readiness. *Needs API-15.* | API-15 | |

## Phase 5: Admin (light)

| ID | Task | Done when |
|---|---|---|
| M-39 | Keep the existing admin screens (stats, analytics, agent performance, users, duty schedules), moved to the new architecture. Add an "Open the admin console" link to the web for everything else. | Nothing admin-only is in the client or agent navigation |

## Phase 6: World-class polish

| ID | Task | Done when |
|---|---|---|
| M-40 | **Offline-first reading:** cache lists and details (`drift` or `isar`), show cached data instantly, then refresh with pull-to-refresh, and show the "last updated" time. A queue for writes (call logs, replies, tasks) with retry. | Airplane mode shows yesterday's data with a clear banner |
| M-41 | **Performance:**<br>• cold start under 2 s on a mid-range Android;<br>• lists paginated and lazily built;<br>• images cached (`cached_network_image`);<br>• no jank (profile with DevTools).<br>Add a performance budget to CI if possible. | Measured and noted in the README |
| M-42 | **Accessibility:** semantics labels, dynamic type up to 200%, 4.5:1 contrast in both modes, 48 dp tap targets, screen-reader passes on iOS and Android, reduced motion respected. | Tested with VoiceOver and TalkBack |
| M-43 | **Deep links and universal links:** `https://surehelpsolution.com/app/...` and `/agent/...` open the app when installed (Android App Links, iOS Associated Domains). Email links (appointment, invitation) do the same. *Needs API-16 for the `assetlinks.json` and `apple-app-site-association` files.* | A link in an email opens the right screen |
| M-44 | **Little things that feel premium:**<br>• haptics on success;<br>• skeleton loaders, not spinners;<br>• optimistic updates (approve, complete, acknowledge) with undo;<br>• search everywhere (`/search`, *API-17*);<br>• app-icon quick actions (New appointment, Log a call);<br>• an iOS home-screen widget and Android widget for today's numbers (later);<br>• the in-app review prompt after a success streak. | |
| M-45 | **Calls from the app (research, then decide):** taking SureHelp calls on the phone needs Twilio Voice SDK with native VoIP push (CallKit / ConnectionService). Write a one-page proposal (effort, store review, battery) before building. *Would need API-18 (a Twilio access token for mobile).* | Proposal reviewed |
| M-46 | **App version check:** on launch, ask the server for the minimum supported version. If the app is older, show a full-screen "Please update" with a store link; if a newer version exists, show a soft banner. *Needs API-19.* | Old builds can be retired safely |

## Phase 7: Testing and release

| ID | Task | Done when |
|---|---|---|
| M-47 | **Tests:**<br>• unit tests for the API client, envelope parsing, error mapping, the auth state machine and the offline queue;<br>• widget tests for every screen state (loading, empty, error, data);<br>• golden tests for the design system;<br>• integration tests (`patrol` or `integration_test`) against a mocked API, plus a smoke test against staging. | Coverage ≥ 70% on `core` and `features/*/data` |
| M-48 | **Store readiness:**<br>• App Store: privacy manifest (`PrivacyInfo.xcprivacy`) and privacy nutrition labels;<br>• Play: Data safety form;<br>• screenshots for both modes, the store listing text, the age rating, export compliance (no own encryption);<br>• a demo account for reviewers (ask the web team: the demo data has `client@surehelpsolution.com`; reviewers need one **without** two-step sign-in, or with a code shared in the review notes). | Submitted to TestFlight and the Play internal track |
| M-49 | **Release process:** Fastlane or Codemagic for signed builds; build numbers from CI; a changelog in the repo; staged rollout on Play; Crashlytics release health watched for 48 h before 100%. | Documented in the README |
| M-50 | **Documentation:** replace `API_INTEGRATION_GUIDE.md`, `COMPLETE_API_DOCUMENTATION.md` and `FLUTTER_API_GUIDE.md` with a short `README` (setup, flavours, running against the local server, release) and link to the web repo's `docs/api.md` as the single API reference. | Old guides removed |

---

## Server (web repo) tasks the app depends on

The web-repo session builds these. Each follows the existing conventions: envelope, tenancy, permissions, idempotency on creates, audit log, tests and a `docs/api.md` entry.

| ID | Endpoint(s) | For | Priority |
|---|---|---|---|
| API-1 ✅ | `POST /login`: no change needed; confirm `device_name`, `expires_at` and the 2FA/verification flags in the docs. Add `GET /me/capabilities` (roles, permissions, enabled features and plan limits for the current business), so the app shows only what the person may use. | M-10, M-2 | **1** |
| API-2 ✅ | Notifications: `GET /notifications` (paginated, with read state), `POST /notifications/{id}/read`, `POST /notifications/read-all`, `GET /notifications/unread-count`. Also: every notification's `toArray` has `url` and `event`, documented as a table of event → app route. | M-16, M-17 | **1** |
| API-3 | Notification settings: `GET/PUT /notifications/settings` (per event and channel, quiet hours); verified mobile number: `POST /account/sms-phone` (send a code), `POST /account/sms-phone/verify`, `DELETE /account/sms-phone`. | M-18 | 2 |
| API-4 ✅ | Account closure: `POST /client/account/closure`, `DELETE /client/account/closure` (cancel during the grace period), `GET` status (D29). | M-19 | **1** (store requirement) |
| API-5 | Version check: `GET /app/version?platform=ios\|android` returning `minimum`, `latest` and `store_url`, set in config or admin. | M-46 | 2 |
| API-6 | Call details: `GET /client/calls/{ulid}` with the transcript, AI summary and a short-lived signed recording URL (permission-checked and audited, D60). | M-21 | 2 |
| API-7 | Inbox actions: assign, close and reopen; start a text conversation (consent rules, D62); attachments (send and receive pictures); mark read. | M-22 | 2 |
| API-8 | Customers: create and edit (with duplicate detection, as the web does), notes. | M-24 | 3 |
| API-9 | Social: create and edit posts (`SavePost` rules, approval), media upload, Write with AI and rewrite (`ContentStudio`, D66), last-minute openings (D69), and link-in-bio settings. | M-26, M-31 | 3 |
| API-10 | Results: `GET /client/results?month=` (the same numbers as the web, including sources D68), `GET /client/results/pdf`. | M-27 | 2 |
| API-11 | Billing: invoices list and detail, the PDF, `POST /client/invoices/{ulid}/pay` returning a Stripe Checkout URL (D64), and automatic payments on or off. | M-29 | 3 |
| API-12 | Team: list members, invite (`OrganizationInvitation`), change role, remove. | M-30 | 3 |
| API-13 | Shift requests: list mine, create a swap or time-off request, cancel (D28). | M-35 | 3 |
| API-14 | QA reviews for agents: list mine, read, confirm (D27). | M-37 | 3 |
| API-15 | Agent availability: `GET/PUT /agent/presence` (the same states as the softphone, D60). | M-38 | 3 |
| API-16 | Serve `/.well-known/assetlinks.json` and `/.well-known/apple-app-site-association` (app ids and paths from config). | M-43 | 2 |
| API-17 | Search: `GET /client/search?q=` (customers, calls, appointments), the same as the web search. | M-44 | 3 |
| API-18 | (Only if M-45 is approved) a Twilio Voice access token for mobile and VoIP push credentials. | M-45 | later |
| API-19 | Generated OpenAPI spec (`/api/v1/openapi.json`), so the app can generate or check its models. | M-4 | 2 |

✅ = built (D70). Paths as built:
- API-1: `GET /me/capabilities`.
- API-2: `GET /notifications`, `GET /notifications/unread-count`, `POST /notifications/{id}/read`, `POST /notifications/read-all`, `DELETE /notifications/{id}`. Push data gains `notification_id`, `route` and `route_params`.
- API-4: `GET/POST/DELETE /account/closure`, for any signed-in person; only the owner may close.

The web repo's `docs/api.md` has the details and the event and route list.

**Suggested order:**
1. **Week 1:** Phase 1 (M-1 to M-9) on the app, and API-1, API-2 and API-4 on the server.
2. **Then:** Phase 2, then Phases 3 and 4 in parallel, then Phases 6 and 7.

## Ground rules for the mobile session

- The server is the source of truth. Don't invent fields. If something is missing, write it down as an API request (copy the table format above) and carry on with the rest.
- Never log tokens or personal data, never store the token outside secure storage, and never send an organization ID.
- Every screen has loading, empty, error and offline states; every create is safe to retry.
- Small PRs, one task each, named after the task id (e.g. `M-10 two-step sign-in`), with screenshots in both light and dark mode.
