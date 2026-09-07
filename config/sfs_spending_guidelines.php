<?php

/**
 * SFS section minimums and maximums (monthly £) — April 2026 source precision.
 * First adult is used once; additional adults use additional_adult.
 */
return [
    'version' => '2026-04-sfs-v6',

    'bands' => [
        'housekeeping' => [
            'first_adult' => ['min' => 317.80, 'max' => 454.00],
            'additional_adult' => ['min' => 233.10, 'max' => 333.00],
            'child_under_16' => ['min' => 137.90, 'max' => 197.00],
            'child_16_18' => ['min' => 164.50, 'max' => 235.00],
        ],
        'comms' => [
            'first_adult' => ['min' => 175.00, 'max' => 250.00],
            'additional_adult' => ['min' => 125.30, 'max' => 179.00],
            'child_under_16' => ['min' => 60.90, 'max' => 87.00],
            'child_16_18' => ['min' => 98.00, 'max' => 140.00],
        ],
        'personal' => [
            'first_adult' => ['min' => 66.50, 'max' => 95.00],
            'additional_adult' => ['min' => 46.90, 'max' => 67.00],
            'child_under_16' => ['min' => 32.90, 'max' => 47.00],
            'child_16_18' => ['min' => 73.50, 'max' => 105.00],
        ],
    ],

    'ui_cap_aliases' => [
        'food' => 'housekeeping',
        'comms_leisure' => 'comms',
        'personal' => 'personal',
    ],
];
