<?php

return [
    'title' => 'Steuern & Gebühren',
    'description' => 'Erstellen und verwalten Sie Steuern, Servicegebühren und sonstige Gebühren. Wenden Sie sie auf Zimmerpreise, Zusatzleistungen oder Rechnungen an.',
    'add' => 'Steuer / Gebühr hinzufügen',
    'new' => 'Neue Steuer / Gebühr',
    'settings' => 'Steuereinstellungen',
    'search' => 'Steuer oder Gebühr suchen…',
    'all_statuses' => 'Alle Status',
    'item_plural' => 'Einträge',
    'select_rule' => 'Wählen Sie eine Steuer oder Gebühr zum Bearbeiten.',
    'no_rules' => 'Noch keine Steuern oder Gebühren',
    'no_rules_hint' => 'Fügen Sie Ihre Steuern und Gebühren hinzu oder übernehmen Sie die Standardregeln Ihres Landes.',
    'templates_banner' => 'Für Ihr Land sind Standard-Steuerregeln verfügbar (Indien: GST 0 % bis ₹1.000, 5 % bis ₹7.500, 18 % darüber, pro Zimmer und Nacht).',
    'copy_templates' => 'Standardregeln übernehmen',

    'tabs' => ['all' => 'Alle', 'tax' => 'Steuern', 'service_charge' => 'Servicegebühren', 'fee' => 'Gebühren'],
    'panel_tabs' => ['details' => 'Details', 'applies' => 'Gilt für', 'advanced' => 'Erweitert'],

    'columns' => [
        'name' => 'Name', 'code' => 'Code', 'type' => 'Art', 'rate' => 'Satz / Betrag', 'apply_to' => 'Gilt für',
        'calculation' => 'Berechnung', 'status' => 'Status', 'default' => 'Standard', 'actions' => 'Aktionen',
    ],

    'kinds' => ['tax' => 'Steuer', 'service_charge' => 'Servicegebühr', 'fee' => 'Gebühr'],
    'tax_types' => [
        'gst' => 'GST', 'vat' => 'MwSt.', 'sales' => 'Umsatzsteuer', 'tourism' => 'Tourismusabgabe', 'city' => 'City-Tax',
        'local' => 'Lokale Abgabe', 'service_charge' => 'Servicegebühr', 'other' => 'Sonstige',
    ],
    'apply_to' => ['room_charges' => 'Zimmer', 'add_ons' => 'Zusatzleistungen', 'fnb' => 'Gastronomie', 'events' => 'Veranstaltungen'],
    'methods' => ['percent' => 'Prozentsatz', 'fixed' => 'Fester Betrag'],
    'bases' => ['per_room_night' => 'Pro Zimmer und Nacht', 'per_person_night' => 'Pro Person und Nacht', 'per_stay' => 'Pro Aufenthalt', 'per_booking' => 'Pro Buchung'],
    'basis_short' => ['per_room_night' => 'pro Zimmer und Nacht', 'per_person_night' => 'pro Person und Nacht', 'per_stay' => 'pro Aufenthalt', 'per_booking' => 'pro Buchung'],
    'component_modes' => ['single' => 'Einzelne Steuer', 'gst_split' => 'GST: CGST + SGST (IGST zwischen Bundesstaaten)'],

    'fields' => [
        'name' => 'Name', 'code' => 'Code', 'type' => 'Art', 'rate' => 'Satz / Betrag', 'basis' => 'Basis', 'apply_to' => 'Gilt für',
        'method' => 'Berechnungsmethode', 'description' => 'Beschreibung', 'options' => 'Optionen', 'status' => 'Status',
        'default_new' => 'Als Standard für neue Zimmertypen festlegen', 'include_displayed' => 'Im angezeigten Gästepreis enthalten',
        'tax_type' => 'Steuerkategorie', 'component_mode' => 'Bestandteile', 'slab' => 'Preisstufe (pro Zimmer und Nacht)',
        'slab_min' => 'Von', 'slab_max' => 'Bis', 'inclusive' => 'Preise enthalten diese Steuer bereits', 'compound' => 'Kumulativ (auf Preis + vorherige Steuern)',
        'priority' => 'Berechnungsreihenfolge', 'effective_from' => 'Gültig ab', 'effective_to' => 'Gültig bis',
        'room_types' => 'Zimmertypen', 'rate_plans' => 'Ratenpläne', 'sac' => 'SAC / HSN',
    ],
    'hints' => [
        'slab' => 'Leer lassen, um bei jedem Preis anzuwenden. Die Grenzen sind eingeschlossen.',
        'applies' => 'Lassen Sie beide Listen leer, um für alle Zimmertypen und Ratenpläne anzuwenden.',
        'priority' => 'Niedrigere Zahlen werden zuerst berechnet.',
    ],

    'slab_range' => ':min – :max',
    'slab_up_to' => 'bis :max',
    'slab_above' => 'über :min',
    'per_room_night_slab' => 'Preis pro Zimmer und Nacht',

    'calculator' => [
        'title' => 'Steuerrechner',
        'intro' => 'Prüfen Sie, welche Steuern mit den aktuellen Regeln auf eine Zimmerbelastung anfallen.',
        'tariff' => 'Preis pro Zimmer und Nacht', 'nights' => 'Nächte', 'persons' => 'Gäste', 'guest_state' => 'Bundesstaat des Gastes (Code)',
        'calculate' => 'Berechnen', 'taxable' => 'Steuerpflichtiger Betrag', 'tax_total' => 'Steuern gesamt', 'total' => 'Gesamt',
        'component' => 'Bestandteil', 'rate' => 'Satz', 'amount' => 'Betrag', 'none' => 'Es fallen keine Steuern an.',
    ],

    'delete_confirm' => ':name löschen? Dies kann nicht rückgängig gemacht werden.',

    'messages' => [
        'created' => 'Steuer / Gebühr angelegt.',
        'updated' => 'Steuer / Gebühr gespeichert.',
        'activated' => 'Steuer / Gebühr aktiviert.',
        'deactivated' => 'Steuer / Gebühr deaktiviert.',
        'deleted' => 'Steuer / Gebühr gelöscht.',
        'templates_copied' => '{0} Keine Regeln übernommen: Sie sind bereits vorhanden.|{1} :count Regel übernommen.|[2,*] :count Regeln übernommen.',
    ],

    'errors' => [
        'in_use' => 'Diese Regel wurde bereits auf Gästebelastungen verwendet und kann nicht gelöscht werden. Deaktivieren Sie sie stattdessen.',
        'apply_to_required' => 'Wählen Sie mindestens eine Belastungsart.',
        'rate_invalid' => 'Geben Sie einen Satz von 0 oder mehr ein.',
        'percent_max' => 'Ein Prozentsatz darf 100 nicht überschreiten.',
        'gst_split_tax_only' => 'Nur Steuern können in CGST und SGST aufgeteilt werden.',
        'slab_order' => 'Die Obergrenze muss mindestens so hoch wie die Untergrenze sein.',
        'dates_order' => 'Das Enddatum muss am oder nach dem Startdatum liegen.',
        'inclusive_percent_only' => 'Nur prozentuale Steuern können in Preisen enthalten sein.',
        'code_taken' => 'Der Code :code wird bereits verwendet.',
    ],
];
