<?php

/*
| Password hashing. Argon2id is the default even when HASH_DRIVER is missing from .env;
| the remaining options come from the framework defaults.
*/

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),
];
