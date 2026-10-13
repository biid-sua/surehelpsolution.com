<?php

namespace App\Livewire\Concerns;

use App\Models\Appointment;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\CustomerLocation;
use App\Models\Organization;
use App\Support\Geo\Address;
use Illuminate\Validation\Rule;

/**
 * The "Location" section of a booking form (D56): an exact address, a saved place (the customer's or the business's),
 * or remote. Shared by the agent workspace and the business's own booking form; the view is partials.visit-location.
 *
 * The using component says where the form state lives, whose places to offer, and what to do when the
 * business location (and so the calendar) changes.
 */
trait EditsVisitLocation
{
    /** Where the location state lives on the component, e.g. "entry.location". */
    abstract protected function locationPath(): string;

    abstract protected function locationOrganizationId(): int;

    /** The customer whose saved places are offered, when one has been chosen. */
    abstract protected function locationCustomer(Organization $organization): ?Customer;

    /** The business location changed, so the free times did too. */
    abstract protected function locationCalendarChanged(): void;

    /** The business location chosen before this update, for this request only. */
    private string $calendarBefore = '';

    /** @return array<string, mixed> */
    protected function location(): array
    {
        return (array) data_get($this, $this->locationPath(), []);
    }

    /** @param  array<string, mixed>  $changes */
    protected function changeLocation(array $changes): void
    {
        data_set($this, $this->locationPath(), array_replace($this->location(), $changes));
    }

    /** The business's own country: the default for a new address, and left off one-line addresses. */
    protected function homeCountry(): string
    {
        $country = BusinessLocation::query()->forOrganization($this->locationOrganizationId())->orderByDesc('is_primary')->orderBy('id')->value('country');

        return isset(Address::countries()[$country ?? '']) ? $country : 'US';
    }

    /** @return array<string, mixed> */
    protected function blankLocation(?int $businessLocationId = null): array
    {
        return ['mode' => $businessLocationId ? 'saved' : 'address', 'saved' => $businessLocationId ? 'b:'.$businessLocationId : '',
            'name' => '', 'type' => 'residential', 'save' => false] + Address::blank($this->homeCountry());
    }

    /** "b:<id>" when the appointment is at one of the business's locations, else "". */
    protected function bookingCalendar(): string
    {
        $location = $this->location();

        return ($location['mode'] ?? '') === 'saved' && str_starts_with((string) ($location['saved'] ?? ''), 'b:') ? $location['saved'] : '';
    }

    /** The business location whose calendar the free times come from. */
    protected function calendarLocationId(?int $fallback): ?int
    {
        $key = $this->bookingCalendar();

        return $key !== '' ? (int) substr($key, 2) : $fallback;
    }

    public function updatingEditsVisitLocation(string $property): void
    {
        $this->calendarBefore = $this->bookingCalendar();
    }

    public function updatedEditsVisitLocation(string $property): void
    {
        $path = $this->locationPath();
        if ($property === "$path.country") {
            $this->changeLocation(['state' => '']);
            $this->resetValidation(["$path.state", "$path.postal_code"]);
        }
        if (in_array($property, ["$path.mode", "$path.saved"], true)) {
            $this->resetValidation([$path, "$path.saved"]);
            if ($this->calendarBefore !== $this->bookingCalendar()) {
                $this->locationCalendarChanged();
            }
        }
    }

    /** A customer was chosen: offer their most recent place, unless an address has been started. */
    protected function offerCustomerLocation(Customer $customer): void
    {
        $location = $this->location();
        $untouched = ($location['mode'] === 'address' && blank($location['line1'])) || ($location['mode'] === 'saved' && blank($location['saved']));
        if ($untouched && $first = array_key_first($this->customerLocations($customer))) {
            $this->changeLocation(['mode' => 'saved', 'saved' => $first]);
        }
    }

    /** The chosen customer changed: their places are no longer on offer. */
    protected function forgetCustomerLocation(): void
    {
        $location = $this->location();
        if (($location['mode'] ?? '') === 'saved' && ! str_starts_with((string) $location['saved'], 'b:')) {
            $this->changeLocation(['mode' => 'address', 'saved' => '']);
        }
    }

    /**
     * The customer's places: the address on their record, then the ones saved for future bookings (most recent first).
     *
     * @return array<string, array{label: string, hint: string, name: ?string, type: ?string, parts: array<string, mixed>, id: ?int}>
     */
    protected function customerLocations(Customer $customer): array
    {
        $home = $this->homeCountry();
        $options = [];
        foreach ($customer->locations()->limit(20)->get() as $saved) {
            $options['c:'.$saved->id] = ['label' => $saved->name ?: (Address::TYPES[$saved->type] ?? 'Saved location'), 'hint' => $saved->singleLine($home),
                'name' => $saved->name, 'type' => $saved->type, 'parts' => $saved->parts(), 'id' => $saved->id];
        }
        $onFile = $customer->singleLineAddress();
        $known = array_map(fn (array $o) => mb_strtolower($o['hint']), $options);
        if ($onFile !== null && ! in_array(mb_strtolower($onFile), $known, true)) {
            $options = ['customer' => ['label' => 'Address on file', 'hint' => $onFile, 'name' => null, 'type' => null, 'id' => null,
                'parts' => ['line1' => $customer->address_line1, 'line2' => $customer->address_line2, 'city' => $customer->city,
                    'state' => $customer->state, 'postal_code' => $customer->postal_code, 'country' => $home]]] + $options;
        }

        return $options;
    }

    /**
     * Everything "Use saved location" offers: the customer's places, then the business's own locations.
     *
     * @return array<string, array{label: string, hint: string, group: string}>
     */
    protected function savedLocations(Organization $organization): array
    {
        $customer = $this->locationCustomer($organization);
        $options = [];
        foreach ($customer ? $this->customerLocations($customer) : [] as $key => $o) {
            $options[$key] = ['label' => $o['label'], 'hint' => $o['hint'], 'group' => 'customer'];
        }
        foreach (BusinessLocation::query()->forOrganization($organization)->orderByDesc('is_primary')->orderBy('name')->get() as $place) {
            $options['b:'.$place->id] = ['label' => 'At '.($place->name ?: 'the business'), 'hint' => $place->singleLine(), 'group' => 'business'];
        }

        return $options;
    }

    /**
     * Checks the location and turns it into booking fields. Null, with errors on the form, when it isn't complete.
     *
     * @param  bool  $optional  a blank address means "no address" (the business's own bookings) rather than an error
     * @param  ?string  $cannotSave  why "save for future use" can't be honoured, when it can't
     * @return array{booking: array<string, mixed>, summary: ?string, saved: ?array<string, mixed>, save: bool}|null
     */
    protected function validatedLocation(Organization $organization, bool $optional = false, ?string $cannotSave = null): ?array
    {
        $path = $this->locationPath();
        $location = $this->location();
        $mode = $location['mode'] ?? '';
        $home = $this->homeCountry();

        if ($mode === 'remote') {
            return ['booking' => ['location_mode' => Appointment::LOCATION_REMOTE], 'summary' => 'Remote (no address)', 'saved' => null, 'save' => false];
        }

        if ($mode === 'saved') {
            $key = (string) ($location['saved'] ?? '');
            if (! isset($this->savedLocations($organization)[$key])) {
                $this->addError("$path.saved", 'Choose a saved location.');

                return null;
            }
            if (str_starts_with($key, 'b:')) {
                $place = BusinessLocation::query()->forOrganization($organization)->findOrFail((int) substr($key, 2));

                return ['booking' => ['location_mode' => Appointment::LOCATION_BUSINESS, 'location_id' => $place->id],
                    'summary' => collect([$place->name, $place->singleLine()])->filter()->implode(' · '), 'saved' => null, 'save' => false];
            }
            $saved = $this->customerLocations($this->locationCustomer($organization))[$key];

            return ['booking' => ['location_mode' => Appointment::LOCATION_ADDRESS, 'address_parts' => $saved['parts'], 'customer_location_id' => $saved['id'],
                'place_name' => $saved['name'], 'place_type' => $saved['type']],
                'summary' => $saved['hint'], 'saved' => $saved, 'save' => false];
        }

        $typed = array_filter(array_intersect_key($location, array_flip(['name', 'line1', 'line2', 'city', 'state', 'postal_code'])), 'filled');
        if ($optional && $typed === []) {
            return ['booking' => [], 'summary' => null, 'saved' => null, 'save' => false];
        }

        $country = isset(Address::countries()[$location['country'] ?? '']) ? $location['country'] : $home;
        $this->validate(Address::rules($path, $country) + [
            "$path.mode" => ['required', Rule::in(['address', 'saved', 'remote'])],
            "$path.name" => ['nullable', 'string', 'max:100'],
            "$path.type" => ['required', Rule::in(array_keys(Address::TYPES))],
            "$path.save" => ['boolean'],
        ], Address::messages($path, $country), ["$path.name" => 'display name']);

        $save = (bool) ($location['save'] ?? false);
        if ($save && $cannotSave !== null) {
            $this->addError("$path.save", $cannotSave);

            return null;
        }
        $parts = Address::normalize(array_intersect_key($location, array_flip(Address::FIELDS)));

        return ['booking' => ['location_mode' => Appointment::LOCATION_ADDRESS, 'address_parts' => $parts,
            'place_name' => trim((string) ($location['name'] ?? '')) ?: null, 'place_type' => $location['type']],
            'summary' => Address::singleLine($parts, $home), 'saved' => null, 'save' => $save];
    }

    /**
     * After booking: a saved place moves to the top of the customer's list, and a new one is saved when asked.
     *
     * @param  array{booking: array<string, mixed>, saved: ?array<string, mixed>, save: bool}  $where
     */
    protected function rememberLocation(Organization $organization, Appointment $appointment, array $where): void
    {
        if ($id = $where['saved']['id'] ?? null) {
            CustomerLocation::query()->forOrganization($organization)->whereKey($id)->update(['last_used_at' => now()]);

            return;
        }
        if (! $where['save'] || ! $appointment->customer_id) {
            return;
        }

        $parts = $where['booking']['address_parts'];
        $fields = ['name' => $where['booking']['place_name'], 'type' => $where['booking']['place_type'], 'address_line1' => $parts['line1'],
            'address_line2' => $parts['line2'], 'city' => $parts['city'], 'state' => $parts['state'], 'postal_code' => $parts['postal_code'],
            'country' => $parts['country'], 'last_used_at' => now()];
        // Saving the same street and postal code again updates that entry instead of adding a duplicate.
        $saved = CustomerLocation::query()->forOrganization($organization)->where('customer_id', $appointment->customer_id)
            ->whereRaw('LOWER(address_line1) = ?', [mb_strtolower((string) $parts['line1'])])
            ->where('postal_code', $parts['postal_code'])->first();
        if ($saved) {
            $saved->update(array_filter($fields, fn ($v) => $v !== null));
        } else {
            $saved = CustomerLocation::create(['organization_id' => $organization->id, 'customer_id' => $appointment->customer_id] + $fields);
        }

        $appointment->forceFill(['customer_location_id' => $saved->id])->saveQuietly();
    }

    /** The form field a booking error about the location belongs on, or null when it isn't about the location. */
    protected function locationErrorField(string $field): ?string
    {
        return in_array($field, ['address', 'location_id', 'location_mode', 'customer_location_id'], true) ? $this->locationPath() : null;
    }

    /**
     * What partials.visit-location needs.
     *
     * @return array<string, mixed>
     */
    protected function locationViewData(Organization $organization, bool $optional = false): array
    {
        $country = (string) ($this->location()['country'] ?? 'US');

        return [
            'loc' => $this->location(),
            'locPath' => $this->locationPath(),
            'locOptional' => $optional,
            'locCustomerChosen' => $this->locationCustomer($organization) !== null,
            'savedLocations' => $this->savedLocations($organization),
            'countries' => Address::countries(),
            'regions' => Address::regions($country),
            'regionLabel' => Address::regionLabel($country),
            'postalLabel' => Address::postalLabel($country),
            'placeTypes' => Address::TYPES,
        ];
    }
}
