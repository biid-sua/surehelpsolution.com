<?php

/*
|--------------------------------------------------------------------------
| Industry templates for the setup wizard (spec ONB-02)
|--------------------------------------------------------------------------
|
| Starting points only: the business ticks what applies and edits names, durations and prices.
| price: dollars (null = no amount); price_type: fixed | starting_from | quote_required | hidden.
|
*/

$service = fn (string $name, int $minutes, string $type, ?int $price = null, bool $bookable = true) => compact('name', 'minutes', 'type', 'price', 'bookable');

return [

    'plumbing' => [
        'label' => 'Plumbing',
        'services' => [
            $service('Drain cleaning', 60, 'starting_from', 149),
            $service('Leak repair', 60, 'starting_from', 129),
            $service('Toilet repair', 60, 'starting_from', 119),
            $service('Water heater repair', 90, 'quote_required'),
            $service('Water heater installation', 180, 'quote_required'),
            $service('Emergency plumbing visit', 60, 'starting_from', 199),
        ],
        'emergencies' => 'Burst pipe or flooding, no water at all, sewage backing up. If the caller smells gas: tell them to leave the building and call the gas company or 911 first.',
        'faqs' => [
            ['Do you charge for estimates?', ''],
            ['Are you licensed and insured?', ''],
            ['Do you offer weekend or emergency service?', ''],
        ],
        'details' => ['address', 'phone'],
    ],

    'hvac' => [
        'label' => 'Heating & air conditioning',
        'services' => [
            $service('AC repair', 90, 'starting_from', 129),
            $service('Furnace / heating repair', 90, 'starting_from', 129),
            $service('Seasonal tune-up', 60, 'fixed', 99),
            $service('New system estimate', 60, 'quote_required'),
            $service('Duct cleaning', 180, 'quote_required'),
        ],
        'emergencies' => 'No heat in freezing weather, no cooling in extreme heat with elderly people, babies or medical needs at home, carbon-monoxide alarm (tell them to leave and call 911).',
        'faqs' => [
            ['Do you offer maintenance plans?', ''],
            ['Which brands do you service?', ''],
            ['Do you offer financing?', ''],
        ],
        'details' => ['address', 'phone'],
    ],

    'electrical' => [
        'label' => 'Electrical',
        'services' => [
            $service('Electrical troubleshooting', 60, 'starting_from', 119),
            $service('Outlet or switch installation', 60, 'starting_from', 99),
            $service('Lighting installation', 90, 'quote_required'),
            $service('Panel upgrade estimate', 60, 'quote_required'),
            $service('EV charger installation', 180, 'quote_required'),
        ],
        'emergencies' => 'Sparks, burning smell, smoke from outlets or the panel, power out to the whole home when neighbours have power. Tell anyone in danger to leave and call 911.',
        'faqs' => [
            ['Are your electricians licensed?', ''],
            ['Do you pull permits?', ''],
        ],
        'details' => ['address', 'phone'],
    ],

    'cleaning' => [
        'label' => 'Cleaning',
        'services' => [
            $service('Standard cleaning', 120, 'starting_from', 120),
            $service('Deep cleaning', 240, 'starting_from', 250),
            $service('Move-in / move-out cleaning', 300, 'quote_required'),
            $service('Office cleaning', 120, 'quote_required'),
        ],
        'emergencies' => '',
        'faqs' => [
            ['Do I need to provide supplies?', ''],
            ['Are your cleaners background-checked?', ''],
            ['What is your cancellation policy?', ''],
        ],
        'details' => ['address', 'phone'],
    ],

    'dental' => [
        'label' => 'Dental practice',
        'services' => [
            $service('New patient exam', 60, 'hidden'),
            $service('Cleaning', 60, 'hidden'),
            $service('Emergency visit', 30, 'hidden'),
            $service('Consultation', 30, 'hidden'),
            $service('Teeth whitening', 60, 'starting_from', 299),
        ],
        'emergencies' => 'Severe pain or swelling, a knocked-out or broken tooth, bleeding that won\'t stop. Facial swelling with fever or trouble breathing or swallowing: tell them to go to the ER or call 911.',
        'faqs' => [
            ['Which insurance plans do you accept?', ''],
            ['Do you see children?', ''],
            ['Do you offer payment plans?', ''],
        ],
        'details' => ['phone', 'email'],
    ],

    'salon' => [
        'label' => 'Salon & spa',
        'services' => [
            $service('Haircut', 45, 'starting_from', 35),
            $service('Color', 120, 'starting_from', 90),
            $service('Blowout', 45, 'fixed', 45),
            $service('Manicure', 45, 'fixed', 35),
            $service('Facial', 60, 'starting_from', 80),
        ],
        'emergencies' => '',
        'faqs' => [
            ['What is your cancellation policy?', ''],
            ['Do you take walk-ins?', ''],
        ],
        'details' => ['phone'],
    ],

    'legal' => [
        'label' => 'Law firm',
        'services' => [
            $service('Initial consultation', 30, 'fixed', 0),
            $service('Case review', 60, 'quote_required'),
        ],
        'emergencies' => 'Someone was just arrested, has a court date within 48 hours, or is in immediate danger (tell them to call 911). Never give legal advice on the phone.',
        'faqs' => [
            ['Is the first consultation free?', ''],
            ['Which practice areas do you handle?', ''],
        ],
        'details' => ['phone', 'email'],
    ],

    'auto' => [
        'label' => 'Auto repair',
        'services' => [
            $service('Oil change', 30, 'starting_from', 49),
            $service('Brake inspection', 60, 'fixed', 0),
            $service('Check-engine diagnostics', 60, 'fixed', 99),
            $service('Tire rotation', 30, 'fixed', 29),
        ],
        'emergencies' => '',
        'faqs' => [
            ['Do you offer loaner cars or shuttles?', ''],
            ['Is there a warranty on repairs?', ''],
        ],
        'details' => ['phone'],
    ],

    'other' => [
        'label' => 'Something else',
        'services' => [],
        'emergencies' => '',
        'faqs' => [],
        'details' => ['phone'],
    ],

];
