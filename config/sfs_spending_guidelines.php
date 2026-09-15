<?php

/**
 * SFS section guidelines (monthly £).
 *
 * The first-adult figure is the standard amount for one adult applicant.
 * Each other figure is added once for every additional adult / child in
 * the applicable household category.
 *
 * There is no separate minimum in the supplied table. min and max are kept
 * equal so existing calculator consumers use these exact section figures.
 */
return [
    'version' => 'supplied-sfs-table-2026-09-15',

    'bands' => [
        'housekeeping' => [
            'first_adult' => ['min' => 454.00, 'max' => 454.00],
            'additional_adult' => ['min' => 333.00, 'max' => 333.00],
            'child_under_16' => ['min' => 197.00, 'max' => 197.00],
            'child_16_18' => ['min' => 235.00, 'max' => 235.00],
        ],
        'comms' => [
            'first_adult' => ['min' => 250.00, 'max' => 250.00],
            'additional_adult' => ['min' => 179.00, 'max' => 179.00],
            'child_under_16' => ['min' => 87.00, 'max' => 87.00],
            'child_16_18' => ['min' => 140.00, 'max' => 140.00],
        ],
        'personal' => [
            'first_adult' => ['min' => 95.00, 'max' => 95.00],
            'additional_adult' => ['min' => 67.00, 'max' => 67.00],
            'child_under_16' => ['min' => 47.00, 'max' => 47.00],
            'child_16_18' => ['min' => 105.00, 'max' => 105.00],
        ],
    ],

    'ui_cap_aliases' => [
        'food' => 'housekeeping',
        'comms_leisure' => 'comms',
        'personal' => 'personal',
    ],
];
