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

    /*
    |--------------------------------------------------------------------------
    | ClamAV Antivirus Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for ClamAV virus scanning service.
    | The service communicates via TCP socket.
    |
    */

    'clamav' => [
        'host' => env('CLAMAV_HOST', '127.0.0.1'),
        'port' => env('CLAMAV_PORT', 3310),
        'timeout' => env('CLAMAV_TIMEOUT', 30),
    ],

];
