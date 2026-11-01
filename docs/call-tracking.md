# Call tracking (D114)

**Call tracking** (portal menu, feature `call_tracking`) gives a business a SureHelp number per
marketing channel and shows which channels bring calls and booked jobs.

## For the business

1. Name the channel (suggestions: Google Ads, Google Local Services, Yelp, Facebook / Instagram,
   Website, Truck or yard signs, Mailers…), enter an area code, and pick a number. Up to 10.
2. Put that number on that channel only.
3. Enter what the channel costs a month to see the cost per booked job.

Calls to a tracking number reach the same receptionists, with the main line's settings (transfer
number, recording, after hours, text-back). Changing the main line under *Business › Phone line*
changes them all.

## The report (`App\Services\Metrics\CallTrackingReport`)

Per number, for a calendar month in the business's time zone:

| Column | Meaning |
|---|---|
| Calls | Inbound calls to that number |
| Answered | Answered, sent to the owner, or still on the call |
| Booked jobs | Calls whose call log has a "booked" outcome |
| Worth | The booked service's price (fixed or "starting from"), else the average job value |
| Spend | What the owner entered for the channel's monthly cost |
| Cost per job | Spend ÷ booked jobs |

The main number is its own row. Removed numbers stay in the months they had calls.

## Code

- `phone_numbers.tracking_source` and `monthly_spend_cents`; `PhoneNumber::isTracking()`.
- `App\Livewire\Client\CallTracking` (route `app.call-tracking`): numbers are bought and released
  through `TelephonyProvider`, audited as `call_tracking.*`.
- `Texting::lineFor()` and *Business › Phone line* use the main line, never a tracking number.

Tests: `tests/Feature/CallTrackingTest.php`.
