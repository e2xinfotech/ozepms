<?php

return [
    'title' => 'Ratenpläne',
    'description' => 'Erstellen und verwalten Sie Ratenpläne: Preise, Verpflegung, Stornobedingungen und Einschränkungen.',
    'add_rate_plan' => 'Ratenplan hinzufügen',
    'edit_rate_plan' => 'Ratenplan bearbeiten',
    'rate_plan' => 'Ratenplan',
    'rate_plan_singular' => 'Ratenplan',
    'rate_plan_plural' => 'Ratenpläne',
    'details_title' => 'Ratenplan-Details',
    'search' => 'Ratenplan nach Name oder Code suchen…',
    'all_room_types' => 'Alle Zimmertypen',
    'all_meal_plans' => 'Alle Verpflegungsarten',
    'all_statuses' => 'Alle Status',
    'copy_suffix' => '(Kopie)',
    'default_badge' => 'Standard',
    'none_linked' => 'Nicht verknüpft',
    'select_plan' => 'Wählen Sie einen Ratenplan, um die Details zu sehen.',
    'no_rate_plans' => 'Noch keine Ratenpläne',
    'no_rate_plans_hint' => 'Legen Sie einen Ratenplan an und verknüpfen Sie ihn mit Ihren Zimmertypen.',

    'kpis' => ['all' => 'Alle Ratenpläne', 'active' => 'Aktiv', 'inactive' => 'Inaktiv', 'mapped' => 'Mit Kanälen verknüpft', 'unmapped' => 'Nicht verknüpft'],

    'columns' => [
        'name' => 'Name des Ratenplans', 'code' => 'Code', 'meal_plan' => 'Verpflegung', 'room_types' => 'Zimmertypen', 'policy' => 'Bedingungen',
        'base_rate' => 'Grundpreis (:currency)', 'min_los' => 'Mindestaufenthalt', 'channels' => 'Kanäle', 'status' => 'Status', 'actions' => 'Aktionen',
    ],

    'panel_tabs' => ['overview' => 'Übersicht', 'rates' => 'Preise & Einschränkungen', 'room_types' => 'Zimmertypen', 'channels' => 'Kanäle'],

    'channels' => [
        'pms' => 'Rezeption (PMS)', 'booking_engine' => 'Buchungsmaschine', 'channels' => 'OTA-Kanäle',
        'mapped' => ':count Produkt(e) mit Kanälen verknüpft', 'not_mapped' => 'Noch mit keinem Kanal verknüpft',
        'mapping_later' => 'Kanalzuordnungen werden im Channel Manager verwaltet.',
    ],

    'quick' => [
        'set_rates' => 'Preise festlegen', 'copy' => 'Kopieren', 'deactivate' => 'Deaktivieren', 'activate' => 'Aktivieren',
        'map_channels' => 'Mit Kanälen verknüpfen', 'view_history' => 'Verlauf anzeigen',
    ],

    'fields' => [
        'name' => 'Name des Ratenplans', 'code' => 'Code', 'description' => 'Beschreibung (für Gäste sichtbar)', 'meal_plan' => 'Verpflegung',
        'policy' => 'Stornobedingungen', 'payment_type' => 'Zahlung', 'deposit_value' => 'Anzahlung',
        'min_los' => 'Mindestaufenthalt', 'max_los' => 'Höchstaufenthalt', 'min_advance' => 'Min. Tage im Voraus',
        'max_advance' => 'Max. Tage im Voraus', 'booking_window' => 'Buchungszeitraum', 'sell_on' => 'Verkauf über',
        'status' => 'Status', 'is_default' => 'Standard-Ratenplan des Hauses', 'price' => 'Preis pro Nacht',
        'base_rate' => 'Grundpreis', 'applicable_to' => 'Gilt für', 'pricing' => 'Preisgestaltung',
        'parent' => 'Abgeleitet von', 'adjust_type' => 'Art der Anpassung', 'adjust_value' => 'Anpassung',
        'single' => 'Einzelbelegung', 'extra_adult' => 'Zusätzlicher Erwachsener', 'extra_child' => 'Zusätzliches Kind',
        'policy_code' => 'Code der Bedingungen', 'policy_name' => 'Name der Bedingungen', 'refundable' => 'Erstattungsfähig',
        'hours_before' => 'Stunden vor Anreise', 'charge' => 'Gebühr', 'charge_value' => 'Wert', 'applies_to' => 'Gilt für',
    ],

    'sections' => [
        'basic' => 'Ratenplan-Details',
        'basic_desc' => 'Ein Ratenplan ist ein Satz kommerzieller Bedingungen. Verknüpfen Sie ihn unten mit Zimmertypen, um ihn zu verkaufen.',
        'policy' => 'Verpflegung, Storno & Zahlung',
        'restrictions' => 'Aufenthaltsregeln & Buchungszeitraum',
        'distribution' => 'Vertrieb',
        'room_types' => 'Zimmertypen & Preise',
        'room_types_desc' => 'Jeder verknüpfte Zimmertyp wird zu einem Produkt. Legen Sie den Preis manuell fest oder leiten Sie ihn von einem anderen Ratenplan ab.',
        'occupancy' => 'Preise nach Belegung',
        'occupancy_desc' => 'Pro Nacht, bezogen auf den Preis der Grundbelegung. Negative Beträge sind Rabatte.',
    ],

    'pricing' => [
        'manual' => 'Manuelle Preise', 'derived' => 'Von einem anderen Ratenplan abgeleitet',
        'manual_hint' => 'Geben Sie für jeden Zimmertyp einen Preis pro Nacht ein.',
        'derived_hint' => 'Die Preise folgen dem übergeordneten Ratenplan desselben Zimmertyps, z. B. −10 %.',
        'derive_all' => 'Alle verknüpften Zimmertypen ableiten von',
        'apply_all' => 'Auf alle anwenden',
    ],
    'adjust_types' => ['percent' => 'Prozent (%)', 'fixed' => 'Fester Betrag', 'fixed_per_person' => 'Fester Betrag pro Person'],
    'occupancy_types' => ['fixed' => 'Betrag', 'percent' => '%'],
    'age_bands' => ['infant' => 'Kleinkind (:min–:max)', 'child' => 'Kind (:min–:max)', 'teen' => 'Jugendlicher (:min–:max)'],

    'payment_types' => [
        'pay_at_property' => 'Zahlung vor Ort', 'prepay_full' => 'Vollständige Vorauszahlung',
        'deposit_percent' => 'Anzahlung (% des Aufenthalts)', 'deposit_nights' => 'Anzahlung (Nächte)',
    ],

    'meal_plans' => [
        'RO' => 'Nur Übernachtung', 'BB' => 'Frühstück', 'HB' => 'Halbpension', 'FB' => 'Vollpension', 'AI' => 'All inclusive',
        'LO' => 'Nur Mittagessen', 'DI' => 'Nur Abendessen', 'BD' => 'Frühstück + Abendessen',
    ],

    'policy' => [
        'refundable' => 'Flexibel', 'non_refundable' => 'Nicht erstattungsfähig', 'new' => 'Neue Bedingungen', 'edit' => 'Bedingungen bearbeiten',
        'rules' => 'Gebühren', 'add_rule' => 'Gebühr hinzufügen', 'cancellation' => 'Stornierung', 'no_show' => 'Nichterscheinen',
        'charge_types' => [
            'none' => 'Kostenlos', 'first_night' => 'Erste Nacht', 'nights' => 'Anzahl Nächte', 'percent' => 'Prozent des Aufenthalts',
            'fixed' => 'Fester Betrag', 'full' => 'Gesamter Aufenthalt',
        ],
        'rule_text' => ':charge bei Stornierung weniger als :hours Std. vor Anreise',
        'no_show_text' => 'Nichterscheinen: :charge',
    ],

    'one_night' => '1 Nacht',
    'n_nights' => ':count Nächte',
    'nights_suffix' => 'Nächte',
    'current_price' => 'Aktueller Preis: :price',
    'daily_rates_hint' => 'Tagespreise und Einschränkungen werden im Kalender festgelegt.',
    'no_room_types' => 'Noch keine Zimmertypen. Legen Sie zuerst einen Zimmertyp an.',
    'occupancy' => [
        'adult' => 'Erwachsene', 'child' => 'Kind', 'infant' => 'Kleinkind', 'guest' => 'Gastart', 'count' => 'Gastanzahl',
        'count_hint' => 'Erwachsene: Erwachsene im Zimmer insgesamt (1 = Einzelbelegung). Kinder: das n-te Kind.',
        'age_band' => 'Altersgruppe', 'add' => 'Regel hinzufügen', 'none' => 'Keine Belegungsregeln: Der Preis gilt für jede Gästeanzahl.',
    ],
    'days_range' => ':from – :to Tage',
    'any' => 'Beliebig',

    'defaults' => [
        'bar_name' => 'Standardrate – Nur Übernachtung',
        'bar_text' => 'Bester verfügbarer Preis ohne Verpflegung.',
        'flexible_name' => 'Flexibel – kostenlose Stornierung bis 24 Stunden vor Anreise',
        'flexible_text' => 'Kostenlose Stornierung bis 24 Stunden vor Anreise. Spätere Stornierungen und Nichterscheinen werden mit der ersten Nacht berechnet.',
        'non_refundable_name' => 'Nicht erstattungsfähig',
        'non_refundable_text' => 'Der gesamte Aufenthalt wird bei der Buchung berechnet und bei Stornierung oder Nichterscheinen nicht erstattet.',
    ],

    'messages' => [
        'created' => 'Ratenplan angelegt.',
        'updated' => 'Ratenplan gespeichert.',
        'activated' => 'Ratenplan aktiviert.',
        'deactivated' => 'Ratenplan deaktiviert.',
        'copied' => 'Kopie als :code angelegt (inaktiv). Prüfen und anschließend aktivieren.',
        'policy_saved' => 'Stornobedingungen gespeichert.',
    ],

    'errors' => [
        'adjust_percent_range' => 'Eine prozentuale Anpassung muss größer als −100 sein.',
        'adjust_required' => 'Geben Sie Art und Wert der Anpassung ein.',
        'age_band_unknown' => 'Unbekannte Altersgruppe.',
        'has_active_children' => ':count aktive(s) Produkt(e) sind hiervon abgeleitet. Ändern Sie diese zuerst.',
        'non_refundable_free' => 'Nicht erstattungsfähige Bedingungen dürfen keine kostenlose Stornierung enthalten.',
        'occupancy_count' => 'Die Gastanzahl muss zwischen 1 und :max liegen.',
        'occupancy_duplicate' => 'Diese Belegungsregel wurde doppelt eingegeben.',
        'parent_cycle' => 'Der Preis würde von sich selbst abhängen.',
        'parent_inactive' => 'Die Verknüpfung des übergeordneten Ratenplans ist inaktiv.',
        'parent_missing' => 'Wählen Sie den Ratenplan, von dem dieser Preis abgeleitet wird.',
        'parent_not_linked' => ':plan ist nicht mit :room_type verknüpft. Verknüpfen Sie ihn zuerst oder verwenden Sie einen manuellen Preis.',
        'parent_other_property' => 'Der übergeordnete Ratenplan gehört zu einem anderen Haus.',
        'parent_self' => 'Ein Ratenplan kann nicht von sich selbst abgeleitet werden.',
        'parent_too_deep' => 'Abgeleitete Preise können höchstens über :max Ebenen verkettet werden.',
        'price_required' => 'Geben Sie einen Preis von 0 oder mehr ein.',
        'product_inactive' => 'Diese Ratenplan-Verknüpfung ist inaktiv.',
        'rule_duplicate_window' => 'Zwei Gebühren verwenden denselben Zeitraum.',
        'rule_percent_max' => 'Ein Prozentsatz darf 100 nicht überschreiten.',
        'rule_value_required' => 'Geben Sie einen Wert für diese Gebühr ein.',
        'max_los_below_min' => 'Der Höchstaufenthalt darf nicht kürzer als der Mindestaufenthalt sein.',
        'window_order' => 'Der späteste Buchungstag muss nach dem frühesten liegen.',
        'deposit_value' => 'Geben Sie eine Anzahlung größer als 0 ein (höchstens 100 bei Prozent).',
        'default_cannot_deactivate' => 'Der Standard-Ratenplan kann nicht deaktiviert werden. Legen Sie zuerst einen anderen Plan als Standard fest.',
        'default_inactive' => 'Ein inaktiver Ratenplan kann nicht Standard sein.',
        'meal_plan_required' => 'Wählen Sie eine Verpflegung.',
        'policy_required' => 'Wählen Sie Stornobedingungen.',
    ],
    'setup' => ['title' => 'Einrichtung, Schritt 2 von 3:', 'text' => 'Legen Sie den Ratenplan an, mit dem Ihre Zimmer verkauft werden. Danach fügen Sie die Zimmertypen und deren PMS-Zimmer hinzu.'],
    'meal_plan_custom' => [
        'button' => 'Eigene', 'title' => 'Eigene Verpflegungsart', 'save' => 'Verpflegungsart hinzufügen', 'includes' => 'Enthaltene Mahlzeiten',
        'breakfast' => 'Frühstück', 'lunch' => 'Mittagessen', 'dinner' => 'Abendessen', 'all_inclusive' => 'All inclusive (Essen und Getränke)',
        'hint' => 'Für Pakete, die die Standardliste nicht abdeckt. Der Code muss sich von den Standardcodes (RO, BB, HB …) unterscheiden.',
        'taken' => 'Eine Verpflegungsart mit diesem Code oder Namen gibt es bereits.', 'saved' => 'Verpflegungsart hinzugefügt.',
    ],
];
