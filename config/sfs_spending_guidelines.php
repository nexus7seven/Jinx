<?php

/**
 * Marginal spending guideline caps (monthly £) for section totals.
 * First adult is included in base_one_adult; additional adults use additional_adult.
 */
return [
    'version' => '2026-04-custom',

    'bands' => [
        'comms_leisure' => [
            'base_one_adult' => 250,
            'additional_adult' => 178,
            'child_under_16' => 87,
            'child_16_18' => 140,
        ],
        'food' => [
            'base_one_adult' => 454,
            'additional_adult' => 333,
            'child_under_16' => 197,
            'child_16_18' => 235,
        ],
        'personal' => [
            'base_one_adult' => 95,
            'additional_adult' => 67,
            'child_under_16' => 47,
            'child_16_18' => 105,
        ],
    ],
];
