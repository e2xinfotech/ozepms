<?php

return [
    'title' => 'Housekeeping',
    'description' => 'Choose who is responsible for cleaning each room. When a guest checks out, that person receives an e-mail to clean the room.',
    'tabs' => ['tasks' => 'Cleaning tasks', 'rooms' => 'Rooms & responsibility', 'staff' => 'Staff'],
    'kpi' => ['pending' => 'Waiting for cleaning', 'unassigned' => 'Unassigned tasks', 'unassigned_rooms' => 'Unassigned rooms', 'staff' => 'Active staff'],

    'columns' => [
        'room' => 'Room', 'room_type' => 'Room Type', 'floor' => 'Floor', 'status' => 'Status', 'responsible' => 'Responsible', 'opened' => 'Opened',
        'email_sent' => 'E-mail', 'name' => 'Name', 'email' => 'E-mail', 'phone' => 'Phone', 'rooms' => 'Rooms', 'actions' => 'Actions', 'housekeeping' => 'Housekeeping',
    ],
    'fields' => ['name' => 'Name', 'email' => 'E-mail', 'phone' => 'Phone', 'notes' => 'Notes', 'status' => 'Status', 'staff' => 'Responsible person'],
    'task_status' => ['pending' => 'Waiting', 'done' => 'Cleaned', 'cancelled' => 'Cancelled'],

    'mark_clean' => 'Mark clean',
    'add_staff' => 'Add Staff Member',
    'edit_staff' => 'Edit Staff Member',
    'delete_staff' => 'Remove',
    'delete_title' => 'Remove :name?',
    'delete_text' => 'Their rooms will have no responsible person. Cleaning e-mails already sent are not affected.',
    'assign_selected' => 'Assign selected rooms',
    'selected_count' => ':count selected',
    'choose_person' => 'Choose a person',
    'nobody' => 'Nobody',
    'assign' => 'Assign',
    'unassigned' => 'Not assigned',
    'sent_to' => 'Sent to :email',
    'not_sent' => 'Not sent yet',
    'waiting_for_person' => 'Waiting for a person to be assigned',
    'no_tasks' => 'No cleaning tasks yet',
    'no_tasks_hint' => 'A task appears here when a guest checks out or a room is marked dirty.',
    'no_staff' => 'No staff yet',
    'no_staff_hint' => 'Add the people who clean rooms, then give them rooms. They only need an e-mail address.',
    'search_rooms' => 'Search room…',
    'all_people' => 'All people',
    'search_staff' => 'Search staff…',
    'staff_hint' => 'Staff do not need a login: they only receive e-mails.',
    'rooms_hint' => 'Select rooms and choose who is responsible for cleaning them.',
    'rooms_count' => '{0} No rooms|{1} :count room|[2,*] :count rooms',
    'status_active' => 'Active',
    'status_inactive' => 'Inactive',

    'messages' => [
        'staff_created' => 'Staff member added.', 'staff_updated' => 'Staff member saved.', 'staff_deleted' => 'Staff member removed.',
        'assigned' => '{1} :count room assigned to :name.|[2,*] :count rooms assigned to :name.',
        'unassigned' => '{1} Responsibility removed from :count room.|[2,*] Responsibility removed from :count rooms.',
    ],
    'errors' => [
        'email_taken' => 'A staff member with this e-mail already exists.',
        'staff_inactive' => 'This person is inactive. Activate them first.',
        'unknown_staff' => 'Unknown staff member.',
        'unknown_room' => 'One of the rooms was not found.',
    ],

    'mail' => [
        'subject' => '{1} :hotel — room :rooms needs cleaning|[2,*] :hotel — :count rooms need cleaning (:rooms)',
        'greeting' => 'Hello :name,',
        'intro' => '{1} A guest has checked out of this room at :hotel. Please clean it:|[2,*] Guests have checked out of these rooms at :hotel. Please clean them:',
        'room' => 'Room :room',
        'outro' => 'Please mark the room as clean in the system when you are done.',
        'salutation' => 'Thank you, :hotel',
    ],
];
