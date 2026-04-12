<?php

return [
    'household_limits' => [
        'adults_min' => 1,
        'adults_max' => 10,
        'children_min' => 0,
        'children_max' => 15,
    ],

    'income' => [
        ['code' => 'salary', 'label' => 'Salary'],
        ['code' => 'partner_salary', 'label' => 'Partner Salary'],
        ['code' => 'self_employed', 'label' => 'Self Employed Income'],
        ['code' => 'universal_credit', 'label' => 'Universal Credit'],
        ['code' => 'child_benefit', 'label' => 'Child Benefit'],
        ['code' => 'child_maintenance_in', 'label' => 'Child Maintenance'],
        ['code' => 'pensions', 'label' => 'Pensions'],
        ['code' => 'other_income', 'label' => 'Other'],
    ],

    'expenditure_sections' => [
        [
            'id' => 'home_contents',
            'title' => 'Home and Contents',
            'cap_band' => null,
            'lines' => [
                ['code' => 'rent_mortgage', 'label' => 'Rent / Mortgage'],
                ['code' => 'council_tax', 'label' => 'Council Tax'],
                ['code' => 'tv_licence', 'label' => 'TV Licence'],
            ],
        ],
        [
            'id' => 'utilities',
            'title' => 'Utilities',
            'cap_band' => null,
            'lines' => [
                ['code' => 'electric', 'label' => 'Electric'],
                ['code' => 'gas', 'label' => 'Gas'],
                ['code' => 'water', 'label' => 'Water'],
            ],
        ],
        [
            'id' => 'comms_leisure',
            'title' => 'Comms & Leisure',
            'cap_band' => 'comms_leisure',
            'lines' => [
                ['code' => 'internet_tv', 'label' => 'Internet / TV'],
                ['code' => 'mobile_phone', 'label' => 'Mobile Phone'],
                ['code' => 'hobbies', 'label' => 'Hobbies'],
            ],
        ],
        [
            'id' => 'transport',
            'title' => 'Transport',
            'cap_band' => null,
            'lines' => [
                ['code' => 'public_transport', 'label' => 'Public Transport'],
                ['code' => 'car_finance', 'label' => 'Car Finance'],
                ['code' => 'car_insurance', 'label' => 'Car Insurance'],
                ['code' => 'car_tax', 'label' => 'Car Tax'],
                ['code' => 'mot_maintenance', 'label' => 'MOT / Maintenance'],
                ['code' => 'breakdown_cover', 'label' => 'Breakdown Cover'],
                ['code' => 'fuel', 'label' => 'Fuel'],
            ],
        ],
        [
            'id' => 'food_house',
            'title' => 'Food / House',
            'cap_band' => 'food',
            'lines' => [
                ['code' => 'food', 'label' => 'Food'],
            ],
        ],
        [
            'id' => 'personal',
            'title' => 'Personal',
            'cap_band' => 'personal',
            'lines' => [
                ['code' => 'personal', 'label' => 'Personal'],
            ],
        ],
        [
            'id' => 'care_health',
            'title' => 'Care and Health',
            'cap_band' => null,
            'lines' => [
                ['code' => 'childcare', 'label' => 'Childcare'],
                ['code' => 'adult_care', 'label' => 'Adult Care'],
                ['code' => 'child_maintenance_out', 'label' => 'Child Maintenance'],
                ['code' => 'prescriptions', 'label' => 'Prescriptions'],
                ['code' => 'dentistry', 'label' => 'Dentistry'],
            ],
        ],
        [
            'id' => 'other_costs',
            'title' => 'Other costs',
            'cap_band' => null,
            'lines' => [
                ['code' => 'other', 'label' => 'Other'],
            ],
        ],
    ],
];
