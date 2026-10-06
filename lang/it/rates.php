<?php

return [
    'title' => 'Tariffe',
    'description' => 'Crea e gestisci le tariffe: prezzi, trattamenti, politiche di cancellazione e restrizioni.',
    'add_rate_plan' => 'Aggiungi tariffa',
    'edit_rate_plan' => 'Modifica tariffa',
    'rate_plan' => 'Tariffa',
    'rate_plan_singular' => 'tariffa',
    'rate_plan_plural' => 'tariffe',
    'details_title' => 'Dettagli tariffa',
    'search' => 'Cerca tariffa per nome o codice…',
    'all_room_types' => 'Tutte le tipologie',
    'all_meal_plans' => 'Tutti i trattamenti',
    'all_statuses' => 'Tutti gli stati',
    'copy_suffix' => '(Copia)',
    'default_badge' => 'Predefinita',
    'none_linked' => 'Non collegata',
    'select_plan' => 'Seleziona una tariffa per vederne i dettagli.',
    'no_rate_plans' => 'Nessuna tariffa',
    'no_rate_plans_hint' => 'Crea una tariffa, poi collegala alle tipologie di camera.',

    'kpis' => ['all' => 'Tutte le tariffe', 'active' => 'Attive', 'inactive' => 'Inattive', 'mapped' => 'Collegate ai canali', 'unmapped' => 'Non collegate'],

    'columns' => [
        'name' => 'Nome tariffa', 'code' => 'Codice', 'meal_plan' => 'Trattamento', 'room_types' => 'Tipologie', 'policy' => 'Politica',
        'base_rate' => 'Tariffa base (:currency)', 'min_los' => 'Soggiorno min', 'channels' => 'Canali', 'status' => 'Stato', 'actions' => 'Azioni',
    ],

    'panel_tabs' => ['overview' => 'Panoramica', 'rates' => 'Tariffe e restrizioni', 'room_types' => 'Tipologie', 'channels' => 'Canali'],

    'channels' => [
        'pms' => 'Reception (PMS)', 'booking_engine' => 'Booking engine', 'channels' => 'Canali OTA',
        'mapped' => ':count prodotto/i collegato/i ai canali', 'not_mapped' => 'Non ancora collegata a nessun canale',
        'mapping_later' => 'I collegamenti ai canali si gestiscono nel Channel Manager.',
    ],

    'quick' => [
        'set_rates' => 'Imposta prezzi', 'copy' => 'Copia', 'deactivate' => 'Disattiva', 'activate' => 'Attiva',
        'map_channels' => 'Collega ai canali', 'view_history' => 'Vedi cronologia',
    ],

    'fields' => [
        'name' => 'Nome tariffa', 'code' => 'Codice', 'description' => 'Descrizione (visibile agli ospiti)', 'meal_plan' => 'Trattamento',
        'policy' => 'Politica di cancellazione', 'payment_type' => 'Pagamento', 'deposit_value' => 'Acconto',
        'min_los' => 'Soggiorno minimo', 'max_los' => 'Soggiorno massimo', 'min_advance' => "Giorni di anticipo min.",
        'max_advance' => "Giorni di anticipo max.", 'booking_window' => 'Finestra di prenotazione', 'sell_on' => 'Vendi su',
        'status' => 'Stato', 'is_default' => 'Tariffa predefinita della struttura', 'price' => 'Prezzo a notte',
        'base_rate' => 'Tariffa base', 'applicable_to' => 'Applicabile a', 'pricing' => 'Prezzo',
        'parent' => 'Derivata da', 'adjust_type' => 'Tipo di variazione', 'adjust_value' => 'Variazione',
        'single' => 'Uso singolo', 'extra_adult' => 'Adulto aggiuntivo', 'extra_child' => 'Bambino aggiuntivo',
        'policy_code' => 'Codice politica', 'policy_name' => 'Nome politica', 'refundable' => 'Rimborsabile',
        'hours_before' => "Ore prima dell'arrivo", 'charge' => 'Penale', 'charge_value' => 'Valore', 'applies_to' => 'Si applica a',
    ],

    'sections' => [
        'basic' => 'Dettagli tariffa',
        'basic_desc' => 'Una tariffa è un insieme di condizioni commerciali. Collegala alle tipologie qui sotto per venderla.',
        'policy' => 'Trattamento, cancellazione e pagamento',
        'restrictions' => 'Regole di soggiorno e finestra di prenotazione',
        'distribution' => 'Distribuzione',
        'room_types' => 'Tipologie e prezzi',
        'room_types_desc' => "Ogni tipologia collegata diventa un prodotto. Imposta il prezzo manualmente o derivalo da un'altra tariffa.",
        'occupancy' => 'Prezzi per occupazione',
        'occupancy_desc' => "A notte, rispetto al prezzo dell'occupazione base. Usa un importo negativo per gli sconti.",
    ],

    'pricing' => [
        'manual' => 'Prezzi manuali', 'derived' => "Derivata da un'altra tariffa",
        'manual_hint' => 'Inserisci un prezzo a notte per ogni tipologia.',
        'derived_hint' => 'I prezzi seguono la tariffa madre della stessa tipologia, es. −10 %.',
        'derive_all' => 'Deriva tutte le tipologie collegate da',
        'apply_all' => 'Applica a tutte',
    ],
    'adjust_types' => ['percent' => 'Percentuale (%)', 'fixed' => 'Importo fisso', 'fixed_per_person' => 'Importo fisso a persona'],
    'occupancy_types' => ['fixed' => 'Importo', 'percent' => '%'],
    'age_bands' => ['infant' => 'Neonato (:min–:max)', 'child' => 'Bambino (:min–:max)', 'teen' => 'Ragazzo (:min–:max)'],

    'payment_types' => [
        'pay_at_property' => 'Pagamento in struttura', 'prepay_full' => 'Pagamento anticipato totale',
        'deposit_percent' => 'Acconto (% del soggiorno)', 'deposit_nights' => 'Acconto (notti)',
    ],

    'meal_plans' => [
        'RO' => 'Solo pernottamento', 'BB' => 'Colazione', 'HB' => 'Mezza pensione', 'FB' => 'Pensione completa', 'AI' => 'All inclusive',
        'LO' => 'Solo pranzo', 'DI' => 'Solo cena', 'BD' => 'Colazione + cena',
    ],

    'policy' => [
        'refundable' => 'Flessibile', 'non_refundable' => 'Non rimborsabile', 'new' => 'Nuova politica', 'edit' => 'Modifica politica',
        'rules' => 'Penali', 'add_rule' => 'Aggiungi penale', 'cancellation' => 'Cancellazione', 'no_show' => 'Mancata presentazione',
        'charge_types' => [
            'none' => 'Gratuita', 'first_night' => 'Prima notte', 'nights' => 'Numero di notti', 'percent' => 'Percentuale del soggiorno',
            'fixed' => 'Importo fisso', 'full' => 'Intero soggiorno',
        ],
        'rule_text' => ":charge se cancellata meno di :hours h prima dell'arrivo",
        'no_show_text' => 'Mancata presentazione: :charge',
    ],

    'one_night' => '1 notte',
    'n_nights' => ':count notti',
    'nights_suffix' => 'notti',
    'current_price' => 'Prezzo attuale: :price',
    'daily_rates_hint' => 'Prezzi e restrizioni giornalieri si impostano nel Calendario.',
    'no_room_types' => 'Nessuna tipologia di camera. Crea prima una tipologia.',
    'occupancy' => [
        'adult' => 'Adulti', 'child' => 'Bambino', 'infant' => 'Neonato', 'guest' => 'Tipo di ospite', 'count' => 'Numero ospiti',
        'count_hint' => 'Adulti: adulti totali in camera (1 = uso singolo). Bambini: l\'n-esimo bambino.',
        'age_band' => 'Fascia di età', 'add' => 'Aggiungi regola', 'none' => 'Nessuna regola: il prezzo è lo stesso per qualsiasi numero di ospiti.',
    ],
    'days_range' => ':from – :to giorni',
    'any' => 'Qualsiasi',

    'defaults' => [
        'bar_name' => 'Tariffa standard – Solo pernottamento',
        'bar_text' => 'Miglior tariffa disponibile senza pasti.',
        'flexible_name' => "Flessibile – cancellazione gratuita fino a 24 ore prima dell'arrivo",
        'flexible_text' => "Cancellazione gratuita fino a 24 ore prima dell'arrivo. Le cancellazioni successive e le mancate presentazioni comportano l'addebito della prima notte.",
        'non_refundable_name' => 'Non rimborsabile',
        'non_refundable_text' => "L'intero soggiorno è addebitato alla prenotazione e non è rimborsato in caso di cancellazione o mancata presentazione.",
    ],

    'messages' => [
        'created' => 'Tariffa creata.',
        'updated' => 'Tariffa salvata.',
        'activated' => 'Tariffa attivata.',
        'deactivated' => 'Tariffa disattivata.',
        'copied' => 'Copia creata con codice :code (inattiva). Verificala e poi attivala.',
        'policy_saved' => 'Politica di cancellazione salvata.',
    ],

    'errors' => [
        'adjust_percent_range' => 'Una variazione percentuale deve essere maggiore di −100.',
        'adjust_required' => 'Inserisci tipo e valore della variazione.',
        'age_band_unknown' => 'Fascia di età sconosciuta.',
        'has_active_children' => ':count prodotto/i attivo/i derivano da questo. Modificali prima.',
        'non_refundable_free' => 'Una politica non rimborsabile non può prevedere una cancellazione gratuita.',
        'occupancy_count' => 'Il numero di ospiti deve essere compreso tra 1 e :max.',
        'occupancy_duplicate' => 'Questa regola di occupazione è inserita due volte.',
        'parent_cycle' => 'Il prezzo dipenderebbe da se stesso.',
        'parent_inactive' => 'Il collegamento della tariffa madre è inattivo.',
        'parent_missing' => 'Scegli la tariffa da cui deriva questo prezzo.',
        'parent_not_linked' => ':plan non è collegata a :room_type. Collegala prima o usa un prezzo manuale.',
        'parent_other_property' => "La tariffa madre appartiene a un'altra struttura.",
        'parent_self' => 'Una tariffa non può derivare da se stessa.',
        'parent_too_deep' => 'I prezzi derivati possono essere concatenati al massimo su :max livelli.',
        'price_required' => 'Inserisci un prezzo pari o superiore a 0.',
        'product_inactive' => 'Questo collegamento della tariffa è inattivo.',
        'rule_duplicate_window' => 'Due penali usano la stessa finestra temporale.',
        'rule_percent_max' => 'Una percentuale non può superare 100.',
        'rule_value_required' => 'Inserisci un valore per questa penale.',
        'max_los_below_min' => 'Il soggiorno massimo non può essere inferiore al minimo.',
        'window_order' => "L'ultimo giorno di prenotazione deve essere successivo al primo.",
        'deposit_value' => 'Inserisci un acconto maggiore di 0 (al massimo 100 per una percentuale).',
        'default_cannot_deactivate' => "La tariffa predefinita non può essere disattivata. Imposta prima un'altra tariffa come predefinita.",
        'default_inactive' => 'Una tariffa inattiva non può essere predefinita.',
        'meal_plan_required' => 'Scegli un trattamento.',
        'policy_required' => 'Scegli una politica di cancellazione.',
    ],
    'setup' => ['title' => 'Configurazione, passo 2 di 3:', 'text' => 'crea la tariffa con cui vendi le camere. Poi aggiungi le tipologie di camera e le relative camere PMS.'],
    'meal_plan_custom' => [
        'button' => 'Personalizzato', 'title' => 'Trattamento personalizzato', 'save' => 'Aggiungi trattamento', 'includes' => 'Pasti inclusi',
        'breakfast' => 'Colazione', 'lunch' => 'Pranzo', 'dinner' => 'Cena', 'all_inclusive' => 'All inclusive (cibo e bevande)',
        'hint' => 'Per i pacchetti non presenti nella lista standard. Il codice deve essere diverso dai codici standard (RO, BB, HB …).',
        'taken' => 'Esiste già un trattamento con questo codice o nome.', 'saved' => 'Trattamento aggiunto.',
    ],
];
