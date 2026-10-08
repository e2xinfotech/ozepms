<?php

return [
    'title' => 'Tasse e supplementi',
    'description' => 'Crea e gestisci tasse, costi di servizio e altri supplementi. Applicali alle tariffe delle camere, agli extra o alle fatture.',
    'add' => 'Aggiungi tassa / supplemento',
    'new' => 'Nuova tassa / supplemento',
    'settings' => 'Impostazioni fiscali',
    'search' => 'Cerca tassa o supplemento…',
    'all_statuses' => 'Tutti gli stati',
    'item_plural' => 'voci',
    'select_rule' => 'Seleziona una tassa o un supplemento da modificare.',
    'no_rules' => 'Nessuna tassa o supplemento',
    'no_rules_hint' => 'Aggiungi tasse e supplementi oppure copia le regole standard del tuo paese.',
    'templates_banner' => 'Sono disponibili regole fiscali standard per il tuo paese (per l\'India: GST 0 % fino a ₹1.000, 5 % fino a ₹7.500, 18 % oltre, per camera a notte).',
    'copy_templates' => 'Copia regole standard',

    'tabs' => ['all' => 'Tutte', 'tax' => 'Tasse', 'service_charge' => 'Costi di servizio', 'fee' => 'Supplementi'],
    'panel_tabs' => ['details' => 'Dettagli', 'applies' => 'Si applica a', 'advanced' => 'Avanzate'],

    'columns' => [
        'name' => 'Nome', 'code' => 'Codice', 'type' => 'Tipo', 'rate' => 'Aliquota / importo', 'apply_to' => 'Si applica a',
        'calculation' => 'Calcolo', 'status' => 'Stato', 'default' => 'Predefinita', 'actions' => 'Azioni',
    ],

    'kinds' => ['tax' => 'Tassa', 'service_charge' => 'Costo di servizio', 'fee' => 'Supplemento'],
    'tax_types' => [
        'gst' => 'GST', 'vat' => 'IVA', 'sales' => 'Imposta sulle vendite', 'tourism' => 'Tassa turistica', 'city' => 'Tassa di soggiorno',
        'local' => 'Tassa locale', 'service_charge' => 'Costo di servizio', 'other' => 'Altro',
    ],
    'apply_to' => ['room_charges' => 'Camere', 'add_ons' => 'Extra', 'fnb' => 'Ristorazione', 'events' => 'Eventi', 'beverage' => 'Bevande', 'liquor' => 'Alcolici'],
    'methods' => ['percent' => 'Percentuale', 'fixed' => 'Importo fisso'],
    'bases' => ['per_room_night' => 'Per camera a notte', 'per_person_night' => 'Per persona a notte', 'per_stay' => 'Per soggiorno', 'per_booking' => 'Per prenotazione'],
    'basis_short' => ['per_room_night' => 'per camera a notte', 'per_person_night' => 'per persona a notte', 'per_stay' => 'per soggiorno', 'per_booking' => 'per prenotazione'],
    'component_modes' => ['single' => 'Tassa unica', 'gst_split' => 'GST: CGST + SGST (IGST tra Stati)'],

    'fields' => [
        'name' => 'Nome', 'code' => 'Codice', 'type' => 'Tipo', 'rate' => 'Aliquota / importo', 'basis' => 'Base', 'apply_to' => 'Si applica a',
        'method' => 'Metodo di calcolo', 'description' => 'Descrizione', 'options' => 'Opzioni', 'status' => 'Stato',
        'default_new' => 'Imposta come predefinita per le nuove tipologie', 'include_displayed' => "Includi nel prezzo mostrato all'ospite",
        'tax_type' => 'Categoria fiscale', 'component_mode' => 'Componenti', 'slab' => 'Scaglione tariffario (per camera a notte)',
        'slab_min' => 'Da', 'slab_max' => 'A', 'inclusive' => 'I prezzi includono già questa tassa', 'compound' => 'Composta (applicata su prezzo + tasse precedenti)',
        'priority' => 'Ordine di calcolo', 'effective_from' => 'Valida dal', 'effective_to' => 'Valida fino al',
        'room_types' => 'Tipologie', 'rate_plans' => 'Tariffe', 'sac' => 'SAC / HSN',
    ],
    'hints' => [
        'slab' => 'Lascia vuoto per applicare a qualsiasi tariffa. I limiti sono inclusi.',
        'applies' => 'Lascia vuote entrambe le liste per applicare a tutte le tipologie e tariffe.',
        'priority' => 'I numeri più bassi sono calcolati per primi.',
    ],

    'slab_range' => ':min – :max',
    'slab_up_to' => 'fino a :max',
    'slab_above' => 'oltre :min',
    'per_room_night_slab' => 'tariffa per camera a notte',

    'calculator' => [
        'title' => 'Calcolatore tasse',
        'intro' => 'Verifica quali tasse si applicano a un addebito camera con le regole attuali.',
        'tariff' => 'Tariffa per camera a notte', 'nights' => 'Notti', 'persons' => 'Ospiti', 'guest_state' => "Codice Stato dell'ospite",
        'calculate' => 'Calcola', 'taxable' => 'Imponibile', 'tax_total' => 'Totale tasse', 'total' => 'Totale',
        'component' => 'Componente', 'rate' => 'Aliquota', 'amount' => 'Importo', 'none' => 'Nessuna tassa applicabile.',
    ],

    'delete_confirm' => 'Eliminare :name? L\'operazione non può essere annullata.',

    'messages' => [
        'created' => 'Tassa / supplemento creato.',
        'updated' => 'Tassa / supplemento salvato.',
        'activated' => 'Tassa / supplemento attivato.',
        'deactivated' => 'Tassa / supplemento disattivato.',
        'deleted' => 'Tassa / supplemento eliminato.',
        'templates_copied' => '{0} Nessuna regola copiata: esistono già.|{1} :count regola copiata.|[2,*] :count regole copiate.',
    ],

    'errors' => [
        'in_use' => 'Questa regola è stata usata su addebiti agli ospiti e non può essere eliminata. Disattivala.',
        'apply_to_required' => 'Scegli almeno un tipo di addebito.',
        'rate_invalid' => 'Inserisci un valore pari o superiore a 0.',
        'percent_max' => 'Una percentuale non può superare 100.',
        'gst_split_tax_only' => 'Solo le tasse possono essere suddivise in CGST e SGST.',
        'slab_order' => 'Il limite superiore deve essere almeno pari a quello inferiore.',
        'dates_order' => 'La data di fine deve essere uguale o successiva alla data di inizio.',
        'inclusive_percent_only' => 'Solo le tasse percentuali possono essere incluse nei prezzi.',
        'code_taken' => 'Il codice :code è già in uso.',
    ],
];
