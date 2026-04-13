<?php

return [
    'paths' => [
        'partner-lead-submit',
    ],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => [
        'https://clearmycredit.co.uk',
        'https://www.clearmycredit.co.uk',
        'http://localhost:5500',
        'http://127.0.0.1:5500',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
