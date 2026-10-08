<?php

return [
    'title' => 'Housekeeping',
    'description' => 'Legen Sie fest, wer für die Reinigung jedes Zimmers zuständig ist. Nach der Abreise eines Gastes erhält diese Person eine E-Mail zur Reinigung des Zimmers.',
    'tabs' => ['tasks' => 'Reinigungsaufgaben', 'rooms' => 'Zimmer & Zuständigkeit', 'staff' => 'Personal'],
    'kpi' => ['pending' => 'Warten auf Reinigung', 'unassigned' => 'Nicht zugewiesene Aufgaben', 'unassigned_rooms' => 'Nicht zugewiesene Zimmer', 'staff' => 'Aktives Personal'],

    'columns' => [
        'room' => 'Zimmer', 'room_type' => 'Zimmertyp', 'floor' => 'Etage', 'status' => 'Status', 'responsible' => 'Zuständig', 'opened' => 'Eröffnet',
        'email_sent' => 'E-Mail', 'name' => 'Name', 'email' => 'E-Mail', 'phone' => 'Telefon', 'rooms' => 'Zimmer', 'actions' => 'Aktionen', 'housekeeping' => 'Housekeeping',
    ],
    'fields' => ['name' => 'Name', 'email' => 'E-Mail', 'phone' => 'Telefon', 'notes' => 'Notizen', 'status' => 'Status', 'staff' => 'Zuständige Person'],
    'task_status' => ['pending' => 'Wartend', 'done' => 'Gereinigt', 'cancelled' => 'Storniert'],

    'mark_clean' => 'Als sauber markieren',
    'add_staff' => 'Mitarbeiter hinzufügen',
    'edit_staff' => 'Mitarbeiter bearbeiten',
    'delete_staff' => 'Entfernen',
    'delete_title' => ':name entfernen?',
    'delete_text' => 'Die Zimmer dieser Person haben danach keine zuständige Person. Bereits gesendete Reinigungs-E-Mails bleiben unverändert.',
    'assign_selected' => 'Ausgewählte Zimmer zuweisen',
    'selected_count' => ':count ausgewählt',
    'choose_person' => 'Person wählen',
    'nobody' => 'Niemand',
    'assign' => 'Zuweisen',
    'unassigned' => 'Nicht zugewiesen',
    'sent_to' => 'Gesendet an :email',
    'not_sent' => 'Noch nicht gesendet',
    'waiting_for_person' => 'Wartet auf eine zuständige Person',
    'no_tasks' => 'Noch keine Reinigungsaufgaben',
    'no_tasks_hint' => 'Eine Aufgabe erscheint hier, wenn ein Gast abreist oder ein Zimmer als schmutzig markiert wird.',
    'no_staff' => 'Noch kein Personal',
    'no_staff_hint' => 'Fügen Sie die Personen hinzu, die Zimmer reinigen, und weisen Sie ihnen Zimmer zu. Eine E-Mail-Adresse genügt.',
    'search_rooms' => 'Zimmer suchen…',
    'all_people' => 'Alle Personen',
    'search_staff' => 'Personal suchen…',
    'staff_hint' => 'Das Personal braucht keinen Zugang: Es erhält nur E-Mails.',
    'rooms_hint' => 'Wählen Sie Zimmer aus und legen Sie fest, wer für die Reinigung zuständig ist.',
    'rooms_count' => '{0} Keine Zimmer|{1} :count Zimmer|[2,*] :count Zimmer',
    'status_active' => 'Aktiv',
    'status_inactive' => 'Inaktiv',

    'messages' => [
        'staff_created' => 'Mitarbeiter hinzugefügt.', 'staff_updated' => 'Mitarbeiter gespeichert.', 'staff_deleted' => 'Mitarbeiter entfernt.',
        'assigned' => '{1} :count Zimmer :name zugewiesen.|[2,*] :count Zimmer :name zugewiesen.',
        'unassigned' => '{1} Zuständigkeit für :count Zimmer entfernt.|[2,*] Zuständigkeit für :count Zimmer entfernt.',
    ],
    'errors' => [
        'email_taken' => 'Ein Mitarbeiter mit dieser E-Mail-Adresse existiert bereits.',
        'staff_inactive' => 'Diese Person ist inaktiv. Bitte zuerst aktivieren.',
        'unknown_staff' => 'Unbekannter Mitarbeiter.',
        'unknown_room' => 'Eines der Zimmer wurde nicht gefunden.',
    ],

    'mail' => [
        'subject' => '{1} :hotel — Zimmer :rooms muss gereinigt werden|[2,*] :hotel — :count Zimmer müssen gereinigt werden (:rooms)',
        'greeting' => 'Hallo :name,',
        'intro' => '{1} Ein Gast ist aus diesem Zimmer im :hotel abgereist. Bitte reinigen Sie es:|[2,*] Gäste sind aus diesen Zimmern im :hotel abgereist. Bitte reinigen Sie sie:',
        'room' => 'Zimmer :room',
        'outro' => 'Bitte markieren Sie das Zimmer im System als sauber, sobald Sie fertig sind.',
        'salutation' => 'Vielen Dank, :hotel',
    ],
];
