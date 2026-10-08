<?php

return [
    'title' => 'Ménage',
    'description' => "Choisissez la personne responsable du nettoyage de chaque chambre. Au départ d'un client, elle reçoit un e-mail pour nettoyer la chambre.",
    'tabs' => ['tasks' => 'Tâches de nettoyage', 'rooms' => 'Chambres et responsable', 'staff' => 'Personnel'],
    'kpi' => ['pending' => 'En attente de nettoyage', 'unassigned' => 'Tâches non affectées', 'unassigned_rooms' => 'Chambres non affectées', 'staff' => 'Personnel actif'],

    'columns' => [
        'room' => 'Chambre', 'room_type' => 'Type de chambre', 'floor' => 'Étage', 'status' => 'Statut', 'responsible' => 'Responsable', 'opened' => 'Ouverte',
        'email_sent' => 'E-mail', 'name' => 'Nom', 'email' => 'E-mail', 'phone' => 'Téléphone', 'rooms' => 'Chambres', 'actions' => 'Actions', 'housekeeping' => 'Ménage',
    ],
    'fields' => ['name' => 'Nom', 'email' => 'E-mail', 'phone' => 'Téléphone', 'notes' => 'Notes', 'status' => 'Statut', 'staff' => 'Personne responsable'],
    'task_status' => ['pending' => 'En attente', 'done' => 'Nettoyée', 'cancelled' => 'Annulée'],

    'mark_clean' => 'Marquer propre',
    'add_staff' => 'Ajouter un membre',
    'edit_staff' => 'Modifier le membre',
    'delete_staff' => 'Retirer',
    'delete_title' => 'Retirer :name ?',
    'delete_text' => "Ses chambres n'auront plus de responsable. Les e-mails de nettoyage déjà envoyés ne sont pas affectés.",
    'assign_selected' => 'Affecter les chambres sélectionnées',
    'selected_count' => ':count sélectionnée(s)',
    'choose_person' => 'Choisir une personne',
    'nobody' => 'Personne',
    'assign' => 'Affecter',
    'unassigned' => 'Non affectée',
    'sent_to' => 'Envoyé à :email',
    'not_sent' => 'Pas encore envoyé',
    'waiting_for_person' => "En attente d'une personne responsable",
    'no_tasks' => 'Aucune tâche de nettoyage',
    'no_tasks_hint' => "Une tâche apparaît ici au départ d'un client ou quand une chambre est marquée sale.",
    'no_staff' => 'Aucun membre du personnel',
    'no_staff_hint' => "Ajoutez les personnes qui nettoient les chambres, puis affectez-leur des chambres. Une adresse e-mail suffit.",
    'search_rooms' => 'Rechercher une chambre…',
    'all_people' => 'Toutes les personnes',
    'search_staff' => 'Rechercher du personnel…',
    'staff_hint' => "Le personnel n'a pas besoin de compte : il reçoit seulement des e-mails.",
    'rooms_hint' => 'Sélectionnez des chambres et choisissez qui est responsable de leur nettoyage.',
    'rooms_count' => '{0} Aucune chambre|{1} :count chambre|[2,*] :count chambres',
    'status_active' => 'Actif',
    'status_inactive' => 'Inactif',

    'messages' => [
        'staff_created' => 'Membre ajouté.', 'staff_updated' => 'Membre enregistré.', 'staff_deleted' => 'Membre retiré.',
        'assigned' => '{1} :count chambre affectée à :name.|[2,*] :count chambres affectées à :name.',
        'unassigned' => '{1} Responsabilité retirée pour :count chambre.|[2,*] Responsabilité retirée pour :count chambres.',
    ],
    'errors' => [
        'email_taken' => 'Un membre du personnel avec cet e-mail existe déjà.',
        'staff_inactive' => "Cette personne est inactive. Activez-la d'abord.",
        'unknown_staff' => 'Membre du personnel inconnu.',
        'unknown_room' => "Une des chambres est introuvable.",
    ],

    'mail' => [
        'subject' => '{1} :hotel — la chambre :rooms doit être nettoyée|[2,*] :hotel — :count chambres à nettoyer (:rooms)',
        'greeting' => 'Bonjour :name,',
        'intro' => '{1} Un client a quitté cette chambre à :hotel. Merci de la nettoyer :|[2,*] Des clients ont quitté ces chambres à :hotel. Merci de les nettoyer :',
        'room' => 'Chambre :room',
        'outro' => 'Merci de marquer la chambre comme propre dans le système une fois terminé.',
        'salutation' => 'Merci, :hotel',
    ],
];
