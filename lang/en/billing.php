<?php

return [
    'types' => [
        'room' => 'Room charge',
        'service' => 'Extra',
        'discount' => 'Discount',
        'adjustment' => 'Adjustment',
        'cancellation_fee' => 'Cancellation fee',
    ],
    'lines' => [
        'cancellation_fee' => 'Cancellation fee',
        'no_show_fee' => 'No-show fee',
    ],
    'reasons' => [
        'stay_changed' => 'Stay changed',
    ],
    'errors' => [
        'invalid_type' => 'Choose a valid charge type.',
        'quantity' => 'The quantity must be greater than zero.',
        'amount_zero' => 'The amount must not be zero.',
        'already_void' => 'This line has already been voided.',
        'room_line_void' => 'Room nights follow the stay. Change the reservation, or post an adjustment or discount.',
        'override_required' => 'Only users allowed to override billing checks can waive this fee.',
        'nothing_to_invoice' => 'There are no charges to invoice.',
        'negative_invoice' => 'The charges add up to a negative amount. Issue a credit note instead.',
        'cannot_cancel_credit_note' => 'A credit note cannot be cancelled.',
        'already_cancelled' => 'This invoice has already been cancelled.',
    ],
];
