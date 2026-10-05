<?php

// Validation messages for the rules the application uses; other rules fall back to English.

return [
    'accepted' => 'Il campo :attribute deve essere accettato.',
    'after' => 'Il campo :attribute deve essere una data successiva al :date.',
    'after_or_equal' => 'Il campo :attribute deve essere una data uguale o successiva al :date.',
    'array' => 'Il campo :attribute deve essere un elenco.',
    'before' => 'Il campo :attribute deve essere una data precedente al :date.',
    'before_or_equal' => 'Il campo :attribute deve essere una data uguale o precedente al :date.',
    'between' => [
        'array' => 'Il campo :attribute deve contenere tra :min e :max elementi.',
        'file' => 'Il file :attribute deve pesare tra :min e :max kilobyte.',
        'numeric' => 'Il campo :attribute deve essere compreso tra :min e :max.',
        'string' => 'Il campo :attribute deve contenere tra :min e :max caratteri.',
    ],
    'boolean' => 'Il campo :attribute deve essere sì o no.',
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'date' => 'Il campo :attribute deve essere una data valida.',
    'date_format' => 'Il campo :attribute deve rispettare il formato :format.',
    'decimal' => 'Il campo :attribute deve avere :decimal cifre decimali.',
    'different' => 'I campi :attribute e :other devono essere diversi.',
    'digits' => 'Il campo :attribute deve contenere :digits cifre.',
    'distinct' => 'Il campo :attribute contiene un valore duplicato.',
    'email' => 'Il campo :attribute deve essere un indirizzo e-mail valido.',
    'exists' => 'Il valore selezionato per :attribute non è valido.',
    'file' => 'Il campo :attribute deve essere un file.',
    'gt' => [
        'numeric' => 'Il campo :attribute deve essere maggiore di :value.',
    ],
    'gte' => [
        'numeric' => 'Il campo :attribute deve essere maggiore o uguale a :value.',
    ],
    'image' => 'Il campo :attribute deve essere un\'immagine.',
    'in' => 'Il valore selezionato per :attribute non è valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'lt' => [
        'numeric' => 'Il campo :attribute deve essere minore di :value.',
    ],
    'lte' => [
        'numeric' => 'Il campo :attribute deve essere minore o uguale a :value.',
    ],
    'max' => [
        'array' => 'Il campo :attribute non può contenere più di :max elementi.',
        'file' => 'Il file :attribute non può superare :max kilobyte.',
        'numeric' => 'Il campo :attribute non può essere maggiore di :max.',
        'string' => 'Il campo :attribute non può superare :max caratteri.',
    ],
    'mimes' => 'Il campo :attribute deve essere un file di tipo: :values.',
    'min' => [
        'array' => 'Il campo :attribute deve contenere almeno :min elementi.',
        'file' => 'Il file :attribute deve pesare almeno :min kilobyte.',
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve contenere almeno :min caratteri.',
    ],
    'numeric' => 'Il campo :attribute deve essere un numero.',
    'password' => [
        'letters' => 'Il campo :attribute deve contenere almeno una lettera.',
        'mixed' => 'Il campo :attribute deve contenere almeno una lettera maiuscola e una minuscola.',
        'numbers' => 'Il campo :attribute deve contenere almeno un numero.',
        'symbols' => 'Il campo :attribute deve contenere almeno un simbolo.',
        'uncompromised' => 'Questa :attribute è comparsa in una violazione di dati. Scegline un\'altra.',
    ],
    'prohibited' => 'Il campo :attribute non è consentito.',
    'regex' => 'Il formato del campo :attribute non è valido.',
    'required' => 'Il campo :attribute è obbligatorio.',
    'required_if' => 'Il campo :attribute è obbligatorio quando :other è :value.',
    'required_with' => 'Il campo :attribute è obbligatorio quando :values è presente.',
    'same' => 'Il campo :attribute deve corrispondere a :other.',
    'size' => [
        'array' => 'Il campo :attribute deve contenere :size elementi.',
        'file' => 'Il file :attribute deve pesare :size kilobyte.',
        'numeric' => 'Il campo :attribute deve essere :size.',
        'string' => 'Il campo :attribute deve contenere :size caratteri.',
    ],
    'string' => 'Il campo :attribute deve essere un testo.',
    'timezone' => 'Il campo :attribute deve essere un fuso orario valido.',
    'ulid' => 'Il campo :attribute deve essere un identificativo valido.',
    'unique' => 'Il valore del campo :attribute è già in uso.',
    'uploaded' => 'Il caricamento di :attribute non è riuscito.',
    'url' => 'Il campo :attribute deve essere un URL valido.',

    'custom' => [],

    'attributes' => [
        'code' => 'codice',
        'email' => 'e-mail',
        'name' => 'nome',
        'password' => 'password',
    ],
];
