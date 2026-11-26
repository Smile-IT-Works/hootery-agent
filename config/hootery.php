<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Hootery Agent
    |--------------------------------------------------------------------------
    |
    | The agent sends this app's requests and exceptions to a Hootery server.
    | It stays silent until it is switched on and has both a server URL and
    | the token issued for this environment (`php artisan hootery:token` on
    | the server), so installing the package alone never affects the app.
    |
    */

    'enabled' => env('HOOTERY_ENABLED', true),

    'url' => env('HOOTERY_URL'),

    'token' => env('HOOTERY_TOKEN'),

    /*
    | Seconds one post to the server may take before it is given up, so a
    | slow or unreachable server never holds up a worker for long.
    */

    'timeout' => (float) env('HOOTERY_TIMEOUT', 1.0),

];
