<?php

return [
    /*
    | Path to the durable JSONL stream written by the whatsapp-detector service.
    */
    'jsonl_path' => env(
        'WHATSAPP_DETECTOR_JSONL',
        '/opt/whatsapp-detector/storage/detections/events.jsonl'
    ),


    /*
    | Optional HTTP JSONL source for detector events. When set, ingest uses this URL instead of local file.
    */
    'events_url' => env('WHATSAPP_DETECTOR_EVENTS_URL'),

    /*
    | Timezone for naive datetime strings from the detector (no Z / no offset), e.g. inferred_message_at.
    */
    'detector_local_timezone' => env('WHATSAPP_DETECTOR_LOCAL_TIMEZONE', 'Europe/London'),

    /*
    | Table holding Vicidial-style leads (default matches common Vicidial installs).
    */
    'leads_table' => env('WHATSAPP_DETECTOR_LEADS_TABLE', 'vicidial_list'),

    'lead_id_column' => env('WHATSAPP_DETECTOR_LEAD_ID_COLUMN', 'lead_id'),

    /*
    | Columns on the leads table to match normalized phone variants against.
    */
    'lead_phone_columns' => ['phone_number', 'alt_phone'],

    'remarketing_tasks_table' => env('WHATSAPP_DETECTOR_REMARKETING_TASKS_TABLE', 'remarketing_tasks'),

    /*
    | Column used to order “latest” flow_start for a lead (usually created_at).
    */
    'remarketing_task_time_column' => env('WHATSAPP_DETECTOR_REMARKETING_TASK_TIME', 'created_at'),

    /*
    | Bearer token for POST /api/whatsapp-detector/events (standalone detector on Windows).
    */
    'api_token' => env('WHATSAPP_DETECTOR_API_TOKEN'),
];
