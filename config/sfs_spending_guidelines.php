<?php

/**
 * SFS section guidelines (monthly £).
 *
 * The first-adult figure is the standard amount for one adult applicant.
 * Each other figure is added once for every additional adult / child in
 * the applicable household category.
 *
 * The supplied table defines the maximum guideline. Zebra requires at least 70%
 * of that maximum; Avondale uses 65% of the same maximum.
 */
return [
    'version' => 'supplied-sfs-table-2026-09-15',

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
