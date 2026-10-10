<?php

return [
    'greeting' => 'Dear :name,',
    'subject' => [
        'confirmed' => 'Booking confirmed :ref — :hotel',
        'received' => 'Booking received :ref — :hotel',
        'modified' => 'Your booking :ref was updated — :hotel',
        'cancelled' => 'Booking cancelled :ref — :hotel',
        'thanks' => 'Thank you for staying with :hotel',
    ],
    'intro' => [
        'confirmed' => 'Thank you for booking with :hotel. Your booking is confirmed.',
        'received' => 'Thank you for booking with :hotel. We have received your booking and will confirm it shortly.',
        'modified' => 'Your booking at :hotel has been updated. These are the current details.',
        'cancelled' => 'Your booking at :hotel has been cancelled.',
        'thanks' => 'Thank you for staying with :hotel. We hope you enjoyed your stay and look forward to welcoming you again.',
    ],
    'labels' => [
        'ref' => 'Booking reference',
        'check_in' => 'Check-in',
        'check_out' => 'Check-out',
        'nights' => 'Nights',
        'rooms' => 'Rooms',
        'guests' => 'Guests',
        'total' => 'Total',
        'fee' => 'Cancellation fee',
    ],
    'guests_value' => 'Adults: :adults · Children: :children',
    'view' => 'View your booking',
    'contact' => 'Questions? Contact :hotel: :contact',
    'salutation' => 'Kind regards, :hotel',
    'footer' => 'This e-mail was sent by :hotel about your booking. Please do not share the booking link with others.',
    'test' => [
        'subject' => 'Test e-mail from :app',
        'body' => 'This is a test e-mail. If you can read it, your e-mail settings work.',
        'sent_with' => 'Sent through :host.',
    ],
];
