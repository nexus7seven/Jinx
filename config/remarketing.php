<?php

return [
    'cbna_scanner_enabled' => env('REMARKETING_CBNA_SCANNER_ENABLED', false),
    'linear_executor_enabled' => env('REMARKETING_LINEAR_EXECUTOR_ENABLED', false),
    'call_hopper' => [
        'enabled' => env('REMARKETING_CALL_HOPPER_ENABLED', false),
        'hold_status' => env('REMARKETING_CALL_HOLD_STATUS', 'HOLD'),
        'ready_status' => env('REMARKETING_CALL_READY_STATUS', 'NEW'),
        'holding_list_id' => env('REMARKETING_CALL_HOLDING_LIST_ID', '5555555555'),
        'campaign_id' => env('REMARKETING_CALL_CAMPAIGN_ID', 'MAIN'),
        'morning_start' => env('REMARKETING_CALL_MORNING_START', '10:00'),
        'morning_end' => env('REMARKETING_CALL_MORNING_END', '12:00'),
        'evening_start' => env('REMARKETING_CALL_EVENING_START', '17:45'),
        'evening_end' => env('REMARKETING_CALL_EVENING_END', '18:00'),
    ],
];
