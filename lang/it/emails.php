<?php

return [
    'greeting' => 'Gentile :name,',
    'subject' => [
        'confirmed' => 'Prenotazione confermata :ref — :hotel',
        'received' => 'Prenotazione ricevuta :ref — :hotel',
        'modified' => 'La tua prenotazione :ref è stata modificata — :hotel',
        'cancelled' => 'Prenotazione annullata :ref — :hotel',
        'thanks' => 'Grazie per aver soggiornato presso :hotel',
    ],
    'intro' => [
        'confirmed' => 'Grazie per aver prenotato presso :hotel. La tua prenotazione è confermata.',
        'received' => 'Grazie per aver prenotato presso :hotel. Abbiamo ricevuto la tua prenotazione e la confermeremo a breve.',
        'modified' => 'La tua prenotazione presso :hotel è stata modificata. Ecco i dettagli aggiornati.',
        'cancelled' => 'La tua prenotazione presso :hotel è stata annullata.',
        'thanks' => 'Grazie per aver soggiornato presso :hotel. Speriamo che il soggiorno ti sia piaciuto e ti aspettiamo di nuovo.',
    ],
    'labels' => [
        'ref' => 'Riferimento prenotazione',
        'check_in' => 'Arrivo',
        'check_out' => 'Partenza',
        'nights' => 'Notti',
        'rooms' => 'Camere',
        'guests' => 'Ospiti',
        'total' => 'Totale',
        'fee' => 'Penale di cancellazione',
    ],
    'guests_value' => 'Adulti: :adults · Bambini: :children',
    'view' => 'Visualizza la tua prenotazione',
    'contact' => 'Domande? Contatta :hotel: :contact',
    'salutation' => 'Cordiali saluti, :hotel',
    'footer' => 'Questa e-mail è stata inviata da :hotel in merito alla tua prenotazione. Non condividere il link della prenotazione con altri.',
    'test' => [
        'subject' => 'E-mail di prova da :app',
        'body' => 'Questa è un’e-mail di prova. Se riesci a leggerla, le impostazioni e-mail funzionano.',
        'sent_with' => 'Inviata tramite :host.',
    ],
];
