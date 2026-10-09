# Calendar sync (Google and Microsoft)

Businesses connect their own calendar under **Business › Calendar sync**. SureHelp then:

- writes every appointment it books into the calendar they choose (and moves or removes it when the booking changes);
- mirrors busy times from the calendars they choose, so agents and the booking engine never offer a time they're busy;
- never overwrites an event someone changed directly in their calendar: the booking is flagged for a person to fix instead.

Only start and end times of other events are stored, never titles, descriptions or attendees. Tokens are encrypted in the database and never sent to the browser.

How it runs:

| What | When |
|---|---|
| Push a booking change to the calendar | Right after the booking is saved (queued job) |
| Refresh busy times | Within seconds via push notifications, and every 10 minutes as a safety net (`calendar:sync`) |
| Renew push notifications | Twice a day (`calendar:sync --renew-push`) |
| Lost access (revoked, password change) | The connection is flagged, the owner gets a "needs reconnecting" alert once, and agents see "Calendar not synced" on that business |

## What the business sees on its Calendar page

Every entry is tagged with where it comes from, and a row of chips at the top switches each source on or off. The choice is remembered per business in that browser.

| Source | Shown as | Tags |
|---|---|---|
| SureHelp bookings | Appointments, coloured by status, linking to the booking | `SureHelp`, plus `Google` / `Outlook` for each connected calendar that holds a copy; `Edited in Google` / `Edited in Outlook` (amber) when someone changed it there and it's waiting for a person |
| Service visits from calls | All-day entries noted on calls | `Visit` |
| Google Calendar | Muted "Busy · *calendar name*" blocks with a blue edge | `Google` |
| Microsoft Outlook / 365 | Muted "Busy · *calendar name*" blocks with a light-blue edge | `Outlook` |

Each provider chip shows its connection state: *Synced*, *Reconnect* or *Sync error*. Hovering shows the account and when it last synced. A provider SureHelp hasn't registered yet doesn't get a chip, and one the business hasn't connected shows as *Not connected*. The feed (`app.calendar.events`) takes `sources=surehelp,visits,google,microsoft` and only returns those, so switched-off sources aren't downloaded at all. The sources are defined in `App\Support\Calendar\CalendarSources`.

## One-time setup (SureHelp, not each business)

Until a provider is configured, businesses see "Coming soon" for it. Replace `https://YOUR-DOMAIN` with the production `APP_URL`.

### Google

1. In [Google Cloud Console](https://console.cloud.google.com/), create a project (e.g. "SureHelp").
2. **APIs & Services › Library**: enable **Google Calendar API**.
3. **APIs & Services › OAuth consent screen**: User type **External**. App name "SureHelp", support email, logo, privacy policy and terms URLs.
   - Scopes: `openid`, `email`, `.../auth/calendar.readonly`, `.../auth/calendar.events`.
   - While in **Testing**, add each test business's Google account under *Test users* (up to 100).
4. **Credentials › Create credentials › OAuth client ID**: type **Web application**.
   - Authorized redirect URIs (both):
     - `https://YOUR-DOMAIN/app/integrations/calendar/google/callback` (businesses)
     - `https://YOUR-DOMAIN/agent/calendar/connect/google/callback` (agents' own calendars, D54)
5. Put the client ID and secret in `.env`:
   ```
   GOOGLE_CALENDAR_CLIENT_ID=...
   GOOGLE_CALENDAR_CLIENT_SECRET=...
   ```
6. **Publish the app and apply for verification.** The calendar scopes are "sensitive", so Google reviews the app (privacy policy, a short video of the connect flow, the reasons above). This takes days to a few weeks. Until it's approved, unverified-app warnings show and only test users can connect.

### Microsoft (Outlook / Microsoft 365)

1. In [Microsoft Entra admin center](https://entra.microsoft.com/) › **App registrations › New registration**.
   - Name "SureHelp"; supported account types **Accounts in any organizational directory and personal Microsoft accounts**.
   - Redirect URIs (Web), both:
     - `https://YOUR-DOMAIN/app/integrations/calendar/microsoft/callback` (businesses)
     - `https://YOUR-DOMAIN/agent/calendar/connect/microsoft/callback` (agents' own calendars, D54)
2. **API permissions › Add › Microsoft Graph › Delegated**: `openid`, `email`, `offline_access`, `User.Read`, `Calendars.ReadWrite`. Admin consent isn't needed for these.
3. **Certificates & secrets › New client secret** (24 months). Put a reminder in your calendar to rotate it before it expires.
4. `.env`:
   ```
   MICROSOFT_CALENDAR_CLIENT_ID=...       # Application (client) ID
   MICROSOFT_CALENDAR_CLIENT_SECRET=...   # the secret's Value, not its ID
   MICROSOFT_CALENDAR_TENANT=common
   ```
5. Optional but recommended for trust: **Branding & properties › Publisher verification** (needs a Microsoft Partner Network ID).

### Both

- After changing `.env`: `php artisan config:cache`.
- Push notifications need the site on **HTTPS**. They use `https://YOUR-DOMAIN/api/webhooks/calendar/google` and `.../microsoft`; nothing to register, the app creates them. Set `CALENDAR_PUSH_ENABLED=false` to use polling only.
- The queue worker must run (it already does through the scheduler on cPanel) so booking changes reach calendars.

## Troubleshooting

| Symptom | Meaning / fix |
|---|---|
| "Needs reconnecting" | Access was removed or expired. The owner clicks **Reconnect**. |
| "Sync problem" | A temporary provider error. It retries every 10 minutes; details are in `last_error` and the log. |
| Booking flagged "changed directly in your calendar" | Someone edited our event in Google/Outlook. Update the booking in SureHelp; we don't overwrite their change. |
| `redirect_uri_mismatch` (Google) / `AADSTS50011` (Microsoft) | The redirect URI registered doesn't exactly match `APP_URL` + the path above (check https, www, trailing slash). |

## Agents' own calendars (D54)

Agents connect their personal Google or Microsoft calendar under *Agent portal › My calendar*. It uses the same app registration and `.env` keys as businesses. Only the second redirect URI above has to be added.

- **Busy times** from the calendars the agent ticks appear on My calendar as "Busy", without titles. They're read live, never stored, and never used for any business's booking availability.
- **Shifts** for the next 60 days are copied into the calendar the agent chooses. They follow changes and disappear when removed. `agent-calendars:sync` catches up nightly at 03:40.
- **Lost access:** the agent is told in the app and sees a "Reconnect" banner.
