<?php

return [
    'paths' => [
        'partner-lead-submit',
    ],

    'allowed_methods' => [
        'POST',
        'OPTIONS',
    ],

    'allowed_origins' => [
        'http://127.0.0.1:5500',
        'http://localhost:5500',
        'https://clearmycredit.co.uk',
        'https://www.clearmycredit.co.uk',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Accept',
        'Origin',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
