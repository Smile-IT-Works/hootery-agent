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
    | The post is sent after the response, so visitors never wait for it, but
    | the worker that served the page does. These budgets cap that wait: the
    | connect timeout bounds an unreachable server, the timeout a slow one.
    */

    'connect_timeout' => (float) env('HOOTERY_CONNECT_TIMEOUT', 0.2),

    'timeout' => (float) env('HOOTERY_TIMEOUT', 0.5),

];
