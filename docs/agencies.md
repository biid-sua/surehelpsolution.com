# Agency resellers (D118)

Marketing agencies resell SureHelp to their clients under their own brand.

## For SureHelp staff: Admin › Agencies (`settings.view` to see, `settings.manage` to change)

1. **New agency**: name, brand colour, logo address, and their share of what clients pay (0–60%).
2. **People**: add by the email of an existing business-portal account, as *Agency owner* or
   *Agency team*. A person belongs to one agency at most.
3. **Businesses**: add by name or ID.

Everything is audited (`agency.*`).

## For the agency: "Your agency" (portal menu, only for its people)

- This month, per business: calls answered, missed calls, bookings, urgent matters waiting, and an
  **Open** button that switches to that business.
- What its businesses paid SureHelp this month, and the agency's share.
- Agency owners: **Add a client business** (name, time zone). They become its owner, finish the
  setup and invite the business owner under *Team*.

## Statements

`agencies:statements` runs daily and, once a month has ended (Eastern time), makes that month's
statement for each agency (`agency_statements`, once per month): what its clients paid SureHelp and
the agency's share at its current percentage. Staff pay it outside SureHelp (bank transfer) and
press **Mark paid** with a reference under *Admin › Agencies*; it's audited as
`agency.statement_paid`. The agency sees its last 12 statements, due or paid, on its dashboard.

## How it works

- `agencies`, `agency_user` (role owner|member), `organizations.agency_id`, and
  `organization_user.agency_id` on memberships that came through an agency.
- `App\Services\Agencies\AgencyDesk`: addMember, removeMember, addBusiness, removeBusiness,
  createBusiness, dashboard. Agency people are managers in each client business; removing a person
  or a business deletes only the memberships marked with the agency.
- `Branding::for()`: a business with an agency is white-labelled, in the agency's colour unless it
  set its own.

Tests: `tests/Feature/AgencyTest.php`.
