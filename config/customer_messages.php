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
            'sms' => '{business}: your {service} is booked for {date} at {time}. Reply here or call {phone} to change it.',
        ],
        'appointment_reminder' => [
            'label' => 'Reminder',
            'when' => 'Before the appointment (you choose how long).',
            'subject' => 'Reminder: {service} on {date} at {time}',
            'body' => "Hi {first_name},\n\nA quick reminder of your appointment for {service} on {date} at {time}.\nAddress: {address}\n\nIf you can't make it, please call us on {phone}.\n\n{business}",
            'sms' => "{business}: reminder of your {service} on {date} at {time}. Reply here if you can't make it.",
            'lead_hours' => 24,
        ],
        'appointment_changed' => [
            'label' => 'Time changed',
            'when' => 'When a confirmed appointment is moved.',
            'subject' => 'Your appointment with {business} has moved',
            'body' => "Hi {first_name},\n\nYour appointment for {service} is now on {date} at {time}.\n\nQuestions? Call us on {phone}.\n\n{business}",
            'sms' => '{business}: your {service} has moved to {date} at {time}. Reply here with any questions.',
        ],
        'appointment_cancelled' => [
            'label' => 'Cancellation',
            'when' => 'When an appointment is cancelled.',
            'subject' => 'Your appointment with {business} was cancelled',
            'body' => "Hi {first_name},\n\nYour appointment for {service} on {date} at {time} has been cancelled.\n\nTo book a new time, call us on {phone}.\n\n{business}",
            'sms' => '{business}: your {service} on {date} at {time} was cancelled. Reply here to book a new time.',
        ],
    ],

    // Spanish defaults (D92), for businesses whose customers see Spanish. Same placeholders.
    'templates_es' => [
        'appointment_confirmed' => [
            'subject' => 'Tu cita con {business} está confirmada',
            'body' => "Hola {first_name},\n\nTu cita para {service} quedó reservada para el {date} a las {time}.\nDirección: {address}\n\n¿Necesitas cambiarla? Llámanos al {phone}.\n\nGracias,\n{business}",
            'sms' => '{business}: tu cita de {service} quedó reservada para el {date} a las {time}. Responde aquí o llama al {phone} para cambiarla.',
        ],
        'appointment_reminder' => [
            'subject' => 'Recordatorio: {service} el {date} a las {time}',
            'body' => "Hola {first_name},\n\nTe recordamos tu cita para {service} el {date} a las {time}.\nDirección: {address}\n\nSi no puedes asistir, llámanos al {phone}.\n\n{business}",
            'sms' => '{business}: recordatorio de tu cita de {service} el {date} a las {time}. Responde aquí si no puedes asistir.',
        ],
        'appointment_changed' => [
            'subject' => 'Tu cita con {business} cambió de horario',
            'body' => "Hola {first_name},\n\nTu cita para {service} ahora es el {date} a las {time}.\n\n¿Preguntas? Llámanos al {phone}.\n\n{business}",
            'sms' => '{business}: tu cita de {service} cambió al {date} a las {time}. Responde aquí si tienes preguntas.',
        ],
        'appointment_cancelled' => [
            'subject' => 'Tu cita con {business} fue cancelada',
            'body' => "Hola {first_name},\n\nTu cita para {service} del {date} a las {time} fue cancelada.\n\nPara reservar otro horario, llámanos al {phone}.\n\n{business}",
            'sms' => '{business}: tu cita de {service} del {date} a las {time} fue cancelada. Responde aquí para reservar otro horario.',
        ],
    ],

];
