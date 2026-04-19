<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'node_binary' => env('NODE_BINARY', '/usr/bin/node'),

    'playwright_browsers_path' => env('PLAYWRIGHT_BROWSERS_PATH', '/var/www/.cache/ms-playwright'),

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
	
	'temp_mail' => [
    'provider' => env('TEMP_MAIL_PROVIDER', 'tempmailio'),
    'key' => env('TEMP_MAIL_API_KEY'),
    'base_url' => env('TEMP_MAIL_BASE_URL', 'https://api.temp-mail.io'),
],

'vicidial' => [
    'partner_list_id' => env('VICIDIAL_PARTNER_LIST_ID'),
    'partner_status' => env('VICIDIAL_PARTNER_STATUS', 'WIP'),
    'website_status' => env('VICIDIAL_WEBSITE_STATUS', 'NEW'),
    'website_source_id' => env('VICIDIAL_WEBSITE_SOURCE_ID', 'WEBSITE-CLEARMYCREDIT'),
    'api_url' => env('VICIDIAL_API_URL'),
    'api_user' => env('VICIDIAL_API_USER'),
    'api_pass' => env('VICIDIAL_API_PASS'),
    'agent_user' => env('VICIDIAL_AGENT_USER'),
    'db_connection' => env('VICIDIAL_DB_CONNECTION'),
    'remarketing_source' => env('REMARKETING_SOURCE', 'remarketing'),
],

    /*
    | Deckard callback_action.php — direct_dial and other actions (full URL to PHP endpoint).
    | Agent credentials stay on the Deckard server in callback_action.php / env there, not in Jinx.
    */
    'deckard' => [
        'callback_url' => env('DECKARD_CALLBACK_URL'),
        'agent_user' => env('VICIDIAL_AGENT_USER'),
        'agent_pass' => env('VICIDIAL_AGENT_PASS'),
    ],

];
