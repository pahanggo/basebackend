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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT')
    ],

    // Raster tiles for Leaflet maps (latlng_picker field) and the static map fallback.
    // The Pahang Go server uses {x}/{y}/{z} order, not the usual {z}/{x}/{y}.
    'map_tiles' => [
        'url' => env('MAP_TILES_URL', 'https://tiles.pahanggo.com/tiles/google-roadmap/{x}/{y}/{z}.png'),
        'attribution' => env('MAP_TILES_ATTRIBUTION', '&copy; Pahang Go'),
    ],

    'google_places' => [
        'key' => env('GOOGLE_PLACES_KEY', 'AIzaSyCsW71wapMGVt1VXrSN7hpfyiawR4mhng4'),
    ],

    'github' => [
        'client_id'     => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect'      => env('GITHUB_REDIRECT')
    ],
];
