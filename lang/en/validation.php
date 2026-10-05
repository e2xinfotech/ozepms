<?php

// Validation messages for the rules the application uses; other rules fall back to English.

return [
    'accepted' => 'The :attribute field must be accepted.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'array' => 'The :attribute field must be a list.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be yes or no.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'email' => 'The :attribute field must be a valid e-mail address.',
    'exists' => 'The selected :attribute is invalid.',
    'file' => 'The :attribute field must be a file.',
    'gt' => [
        'numeric' => 'The :attribute field must be greater than :value.',
    ],
    'gte' => [
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
    ],
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'integer' => 'The :attribute field must be a whole number.',
    'lt' => [
        'numeric' => 'The :attribute field must be less than :value.',
    ],
    'lte' => [
        'numeric' => 'The :attribute field must be less than or equal to :value.',
    ],
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be larger than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be longer than :max characters.',
    ],
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'This :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'prohibited' => 'The :attribute field is not allowed.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'string' => 'The :attribute field must be text.',
    'timezone' => 'The :attribute field must be a valid time zone.',
    'ulid' => 'The :attribute field must be a valid identifier.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'url' => 'The :attribute field must be a valid URL.',

    'custom' => [],

    'attributes' => [
        'code' => 'code',
        'email' => 'e-mail',
        'name' => 'name',
        'password' => 'password',
    ],
];
