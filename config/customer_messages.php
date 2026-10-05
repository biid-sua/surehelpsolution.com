<?php

/*
|--------------------------------------------------------------------------
| Emails to a business's customers (spec §26–27)
|--------------------------------------------------------------------------
|
| Defaults until a business edits its own copy. Placeholders in {braces} are filled per booking;
| an empty one disappears with the words around it on the same line if the line becomes empty.
|
*/

return [

    'placeholders' => [
        'first_name' => 'Customer\'s first name',
        'business' => 'Your business name',
        'service' => 'What was booked',
        'date' => 'Day and date, e.g. Tuesday, October 14',
        'time' => 'Start time, e.g. 10:00 AM',
        'address' => 'Visit address',
        'phone' => 'Your business phone',
    ],

    'templates' => [
        'appointment_confirmed' => [
            'label' => 'Booking confirmation',
            'when' => 'As soon as an appointment is confirmed.',
            'subject' => 'Your appointment with {business} is confirmed',
            'body' => "Hi {first_name},\n\nYour appointment for {service} is booked for {date} at {time}.\nAddress: {address}\n\nNeed to change it? Call us on {phone}.\n\nThank you,\n{business}",
        ],
        'appointment_reminder' => [
            'label' => 'Reminder',
            'when' => 'Before the appointment (you choose how long).',
            'subject' => 'Reminder: {service} on {date} at {time}',
            'body' => "Hi {first_name},\n\nA quick reminder of your appointment for {service} on {date} at {time}.\nAddress: {address}\n\nIf you can't make it, please call us on {phone}.\n\n{business}",
            'lead_hours' => 24,
        ],
        'appointment_changed' => [
            'label' => 'Time changed',
            'when' => 'When a confirmed appointment is moved.',
            'subject' => 'Your appointment with {business} has moved',
            'body' => "Hi {first_name},\n\nYour appointment for {service} is now on {date} at {time}.\n\nQuestions? Call us on {phone}.\n\n{business}",
        ],
        'appointment_cancelled' => [
            'label' => 'Cancellation',
            'when' => 'When an appointment is cancelled.',
            'subject' => 'Your appointment with {business} was cancelled',
            'body' => "Hi {first_name},\n\nYour appointment for {service} on {date} at {time} has been cancelled.\n\nTo book a new time, call us on {phone}.\n\n{business}",
        ],
    ],

];
