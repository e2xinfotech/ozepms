<?php

return [
    'title' => 'Governante',
    'description' => 'Scegli chi è responsabile della pulizia di ogni camera. Quando un ospite effettua il check-out, questa persona riceve un\'e-mail per pulire la camera.',
    'tabs' => ['tasks' => 'Attività di pulizia', 'rooms' => 'Camere e responsabile', 'staff' => 'Personale'],
    'kpi' => ['pending' => 'In attesa di pulizia', 'unassigned' => 'Attività non assegnate', 'unassigned_rooms' => 'Camere non assegnate', 'staff' => 'Personale attivo'],

    'columns' => [
        'room' => 'Camera', 'room_type' => 'Tipo di camera', 'floor' => 'Piano', 'status' => 'Stato', 'responsible' => 'Responsabile', 'opened' => 'Aperta',
        'email_sent' => 'E-mail', 'name' => 'Nome', 'email' => 'E-mail', 'phone' => 'Telefono', 'rooms' => 'Camere', 'actions' => 'Azioni', 'housekeeping' => 'Pulizia',
    ],
    'fields' => ['name' => 'Nome', 'email' => 'E-mail', 'phone' => 'Telefono', 'notes' => 'Note', 'status' => 'Stato', 'staff' => 'Persona responsabile'],
    'task_status' => ['pending' => 'In attesa', 'done' => 'Pulita', 'cancelled' => 'Annullata'],

    'mark_clean' => 'Segna pulita',
    'add_staff' => 'Aggiungi collaboratore',
    'edit_staff' => 'Modifica collaboratore',
    'delete_staff' => 'Rimuovi',
    'delete_title' => 'Rimuovere :name?',
    'delete_text' => 'Le sue camere resteranno senza responsabile. Le e-mail di pulizia già inviate non cambiano.',
    'assign_selected' => 'Assegna le camere selezionate',
    'selected_count' => ':count selezionate',
    'choose_person' => 'Scegli una persona',
    'nobody' => 'Nessuno',
    'assign' => 'Assegna',
    'unassigned' => 'Non assegnata',
    'sent_to' => 'Inviata a :email',
    'not_sent' => 'Non ancora inviata',
    'waiting_for_person' => 'In attesa di una persona responsabile',
    'no_tasks' => 'Nessuna attività di pulizia',
    'no_tasks_hint' => 'Un\'attività compare qui al check-out di un ospite o quando una camera è segnata da pulire.',
    'no_staff' => 'Nessun collaboratore',
    'no_staff_hint' => 'Aggiungi chi pulisce le camere, poi assegna loro le camere. Basta un indirizzo e-mail.',
    'search_rooms' => 'Cerca camera…',
    'all_people' => 'Tutte le persone',
    'search_staff' => 'Cerca personale…',
    'staff_hint' => 'Il personale non ha bisogno di un accesso: riceve solo e-mail.',
    'rooms_hint' => 'Seleziona le camere e scegli chi è responsabile della pulizia.',
    'rooms_count' => '{0} Nessuna camera|{1} :count camera|[2,*] :count camere',
    'status_active' => 'Attivo',
    'status_inactive' => 'Inattivo',

    'messages' => [
        'staff_created' => 'Collaboratore aggiunto.', 'staff_updated' => 'Collaboratore salvato.', 'staff_deleted' => 'Collaboratore rimosso.',
        'assigned' => '{1} :count camera assegnata a :name.|[2,*] :count camere assegnate a :name.',
        'unassigned' => '{1} Responsabilità rimossa da :count camera.|[2,*] Responsabilità rimossa da :count camere.',
    ],
    'errors' => [
        'email_taken' => 'Esiste già un collaboratore con questa e-mail.',
        'staff_inactive' => 'Questa persona è inattiva. Attivala prima.',
        'unknown_staff' => 'Collaboratore sconosciuto.',
        'unknown_room' => 'Una delle camere non è stata trovata.',
    ],

    'mail' => [
        'subject' => '{1} :hotel — la camera :rooms va pulita|[2,*] :hotel — :count camere da pulire (:rooms)',
        'greeting' => 'Ciao :name,',
        'intro' => '{1} Un ospite ha lasciato questa camera presso :hotel. Per favore puliscila:|[2,*] Gli ospiti hanno lasciato queste camere presso :hotel. Per favore puliscile:',
        'room' => 'Camera :room',
        'outro' => 'Al termine, segna la camera come pulita nel sistema.',
        'salutation' => 'Grazie, :hotel',
    ],
];
