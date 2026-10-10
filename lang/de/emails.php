<?php

return [
    'greeting' => 'Guten Tag :name,',
    'subject' => [
        'confirmed' => 'Buchung bestätigt :ref — :hotel',
        'received' => 'Buchung eingegangen :ref — :hotel',
        'modified' => 'Ihre Buchung :ref wurde geändert — :hotel',
        'cancelled' => 'Buchung storniert :ref — :hotel',
        'thanks' => 'Vielen Dank für Ihren Aufenthalt im :hotel',
    ],
    'intro' => [
        'confirmed' => 'Vielen Dank für Ihre Buchung im :hotel. Ihre Buchung ist bestätigt.',
        'received' => 'Vielen Dank für Ihre Buchung im :hotel. Wir haben Ihre Buchung erhalten und bestätigen sie in Kürze.',
        'modified' => 'Ihre Buchung im :hotel wurde geändert. Hier sind die aktuellen Angaben.',
        'cancelled' => 'Ihre Buchung im :hotel wurde storniert.',
        'thanks' => 'Vielen Dank für Ihren Aufenthalt im :hotel. Wir hoffen, es hat Ihnen gefallen, und freuen uns auf ein Wiedersehen.',
    ],
    'labels' => [
        'ref' => 'Buchungsnummer',
        'check_in' => 'Anreise',
        'check_out' => 'Abreise',
        'nights' => 'Nächte',
        'rooms' => 'Zimmer',
        'guests' => 'Gäste',
        'total' => 'Gesamtbetrag',
        'fee' => 'Stornogebühr',
    ],
    'guests_value' => 'Erwachsene: :adults · Kinder: :children',
    'view' => 'Buchung ansehen',
    'contact' => 'Fragen? Kontaktieren Sie :hotel: :contact',
    'salutation' => 'Freundliche Grüße, :hotel',
    'footer' => 'Diese E-Mail wurde von :hotel zu Ihrer Buchung gesendet. Bitte geben Sie den Buchungslink nicht an andere weiter.',
    'test' => [
        'subject' => 'Test-E-Mail von :app',
        'body' => 'Dies ist eine Test-E-Mail. Wenn Sie sie lesen können, funktionieren Ihre E-Mail-Einstellungen.',
        'sent_with' => 'Gesendet über :host.',
    ],
];
