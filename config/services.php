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

    'routing' => [
        'provider' => env('ROUTING_PROVIDER', 'openrouteservice'),
        'allow_osrm_when_unconfigured' => (bool) env('ROUTING_ALLOW_OSRM_FALLBACK', true),
    ],

    'openrouteservice' => [
        'key' => env('OPENROUTESERVICE_API_KEY', ''),
        'base_url' => env('OPENROUTESERVICE_BASE_URL', 'https://api.openrouteservice.org'),
        'profile' => env('OPENROUTESERVICE_PROFILE', 'driving-car'),
        'timeout' => (int) env('OPENROUTESERVICE_TIMEOUT', 6),
    ],

    'osrm' => [
        'base_url' => env('OSRM_BASE_URL', 'https://router.project-osrm.org'),
        'profile' => env('OSRM_PROFILE', 'driving'),
        'timeout' => (int) env('OSRM_TIMEOUT', 5),
    ],

];
