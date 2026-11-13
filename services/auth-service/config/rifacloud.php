<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legal documents and informed consent (S11)
    |--------------------------------------------------------------------------
    |
    | Must match the monolith and the React app. The version identifies the
    | exact privacy policy and prototype terms the user accepted; the API
    | rejects stale versions instead of storing misleading evidence.
    |
    */

    'legal' => [
        'consent_version' => env('LEGAL_CONSENT_VERSION', '2026-11-11'),
    ],

];
