# Instant replies to new leads (D109)

People who ask a business for work usually go with whoever answers first. When a new lead comes in,
SureHelp answers within seconds in the business's name, and rings the owner to connect them.

## Where leads come from

- **The website form** in the SureHelp snippet (`#surehelp-lead`).
- **The business's lead link**, `POST /api/leads/{secret}`, for lead sources through a connector such
  as Zapier or Make: Facebook lead ads, Google (Local Services or Ads), Angi, Thumbtack, Yelp,
  Nextdoor. It takes JSON or form fields and understands the usual names: `name`, `full_name`,
  `first_name` + `last_name`, `phone` or `phone_number`, `email`, `message`, `comments`. Put
  `source` in the fields or the link (`?source=facebook`). Other plain fields (up to 15) are kept
  with the lead.
  - Limited to 60 a minute.
  - "Make a new link" replaces the secret, and the old link stops working.
  - The same phone or email again within 10 minutes is the same lead, because lead sources retry.

Every lead becomes a customer (origin "A lead source", or "Your website"), a high-priority call-back
task, and a *New lead* alert for everyone who sees customers. That happens whether instant replies
are on or not.

## The instant answer (*Leads*, owners and managers)

- **A text** from the business's SureHelp number, in its words. `{first_name}` and `{business}` are
  filled in, and "Reply STOP to opt out" is added if it's missing. The texting rules apply (D61):
  never to a number that texted STOP, and only once texting is on for the line. Between 9 PM and 8
  AM in the business's time zone the text waits for 8 AM. The conversation continues in the Inbox,
  where the AI assistant can reply if it's on for texts.
- **An email** in the business's name, with replies going to its business email. It's a direct
  answer to the person's own request, like appointment emails.
- **Ring me and connect me.** The owner's phone (the line's transfer number) rings from the
  business's number. They hear "New lead: Ana Lopez, from Facebook lead ad… Press 1 to call them
  now". Pressing 1 rings the lead, showing the business's number. By default this happens only
  during opening hours.

It runs right after the lead arrives, not on the next run of the queue (`dispatchAfterResponse`).
Demo businesses never text, email or call anyone (D94).

## What the business sees

*Leads* shows this month's leads, the typical time to the first answer, and the share answered
within a minute. Each lead shows what was sent and how fast ("Texted in 3 s"), the call
("You were connected"), and why something wasn't sent ("no text: texting is off or they opted
out"). The feature is `lead_response`, open to every business by default (*Billing › Feature
access*). Nothing is sent until the business turns instant replies on.

## Technical notes

- `LeadIntake` takes leads in, `LeadResponder` and `RespondToLead` answer them, and
  `LeadWebhookController` serves the lead link.
- `LeadCallController` handles the owner call (`/api/webhooks/voice/leads/{lead}/…`, signed by
  Twilio). `TelephonyProvider::placeCall()` starts it.
- Tables `leads` and `lead_settings`. Tests: `LeadResponseTest`.
