<?php

// Validation messages for the rules the application uses; other rules fall back to English.

return [
    'accepted' => 'Das Feld :attribute muss akzeptiert werden.',
    'after' => 'Das Feld :attribute muss ein Datum nach dem :date sein.',
    'after_or_equal' => 'Das Feld :attribute muss ein Datum am oder nach dem :date sein.',
    'array' => 'Das Feld :attribute muss eine Liste sein.',
    'before' => 'Das Feld :attribute muss ein Datum vor dem :date sein.',
    'before_or_equal' => 'Das Feld :attribute muss ein Datum am oder vor dem :date sein.',
    'between' => [
        'array' => 'Das Feld :attribute muss zwischen :min und :max Einträge haben.',
        'file' => 'Die Datei :attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss zwischen :min und :max liegen.',
        'string' => 'Das Feld :attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => 'Das Feld :attribute muss Ja oder Nein sein.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => 'Das Feld :attribute muss ein gültiges Datum sein.',
    'date_format' => 'Das Feld :attribute muss dem Format :format entsprechen.',
    'decimal' => 'Das Feld :attribute muss :decimal Nachkommastellen haben.',
    'different' => 'Die Felder :attribute und :other müssen sich unterscheiden.',
    'digits' => 'Das Feld :attribute muss :digits Ziffern haben.',
    'distinct' => 'Das Feld :attribute enthält einen doppelten Wert.',
    'email' => 'Das Feld :attribute muss eine gültige E-Mail-Adresse sein.',
    'exists' => 'Der gewählte Wert für :attribute ist ungültig.',
    'file' => 'Das Feld :attribute muss eine Datei sein.',
    'gt' => [
        'numeric' => 'Das Feld :attribute muss größer als :value sein.',
    ],
    'gte' => [
        'numeric' => 'Das Feld :attribute muss größer oder gleich :value sein.',
    ],
    'image' => 'Das Feld :attribute muss ein Bild sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer' => 'Das Feld :attribute muss eine ganze Zahl sein.',
    'lt' => [
        'numeric' => 'Das Feld :attribute muss kleiner als :value sein.',
    ],
    'lte' => [
        'numeric' => 'Das Feld :attribute muss kleiner oder gleich :value sein.',
    ],
    'max' => [
        'array' => 'Das Feld :attribute darf höchstens :max Einträge haben.',
        'file' => 'Die Datei :attribute darf höchstens :max Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute darf nicht größer als :max sein.',
        'string' => 'Das Feld :attribute darf höchstens :max Zeichen lang sein.',
    ],
    'mimes' => 'Das Feld :attribute muss eine Datei vom Typ :values sein.',
    'min' => [
        'array' => 'Das Feld :attribute muss mindestens :min Einträge haben.',
        'file' => 'Die Datei :attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss mindestens :min sein.',
        'string' => 'Das Feld :attribute muss mindestens :min Zeichen lang sein.',
    ],
    'numeric' => 'Das Feld :attribute muss eine Zahl sein.',
    'password' => [
        'letters' => 'Das Feld :attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => 'Das Feld :attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => 'Das Feld :attribute muss mindestens eine Ziffer enthalten.',
        'symbols' => 'Das Feld :attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Dieses :attribute ist in einem Datenleck aufgetaucht. Bitte wählen Sie ein anderes.',
    ],
    'prohibited' => 'Das Feld :attribute ist nicht erlaubt.',
    'regex' => 'Das Format des Feldes :attribute ist ungültig.',
    'required' => 'Das Feld :attribute ist erforderlich.',
    'required_if' => 'Das Feld :attribute ist erforderlich, wenn :other :value ist.',
    'required_with' => 'Das Feld :attribute ist erforderlich, wenn :values angegeben ist.',
    'same' => 'Das Feld :attribute muss mit :other übereinstimmen.',
    'size' => [
        'array' => 'Das Feld :attribute muss :size Einträge enthalten.',
        'file' => 'Die Datei :attribute muss :size Kilobyte groß sein.',
        'numeric' => 'Das Feld :attribute muss :size sein.',
        'string' => 'Das Feld :attribute muss :size Zeichen lang sein.',
    ],
    'string' => 'Das Feld :attribute muss Text sein.',
    'timezone' => 'Das Feld :attribute muss eine gültige Zeitzone sein.',
    'ulid' => 'Das Feld :attribute muss eine gültige Kennung sein.',
    'unique' => 'Der Wert für :attribute ist bereits vergeben.',
    'uploaded' => 'Das Hochladen von :attribute ist fehlgeschlagen.',
    'url' => 'Das Feld :attribute muss eine gültige URL sein.',

    'custom' => [],

    'attributes' => [
        'code' => 'Code',
        'email' => 'E-Mail',
        'name' => 'Name',
        'password' => 'Passwort',
    ],
];
