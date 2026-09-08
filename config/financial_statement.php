<?php

return [
    'household_limits' => [
        'adults_min' => 1,
        'adults_max' => 10,
        'children_min' => 0,
        'children_max' => 15,
    ],

    'income' => [
        ['code' => 'client_salary', 'label' => 'Salary'],
        ['code' => 'partner_salary', 'label' => 'Partner Salary'],
        ['code' => 'self_employed', 'label' => 'Self Employed Income'],
        ['code' => 'universal_credit', 'label' => 'Universal Credit'],
        ['code' => 'child_benefit', 'label' => 'Child Benefit'],
        ['code' => 'maintenance_received', 'label' => 'Child Maintenance'],
        ['code' => 'pension', 'label' => 'Pensions'],
        ['code' => 'pip_dla', 'label' => 'PIP / DLA'],
        ['code' => 'esa', 'label' => 'ESA'],
        ['code' => 'carers_allowance', 'label' => "Carer's Allowance"],
        ['code' => 'student', 'label' => 'Student loan / grant / bursary'],
        ['code' => 'foster_guardianship', 'label' => 'Foster / Guardianship Allowance'],
        ['code' => 'other_income', 'label' => 'Other'],
        ['code' => 'uc_advance_add_back', 'label' => 'UC Advance add-back'],
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
                ['code' => 'electricity', 'label' => 'Electric'],
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
                ['code' => 'road_tax', 'label' => 'Car Tax'],
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
                ['code' => 'housekeeping', 'label' => 'Food'],
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
                ['code' => 'maintenance_paid', 'label' => 'Child Maintenance'],
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
