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

    'enabled' => filter_var(env('HOOTERY_ENABLED', true), FILTER_VALIDATE_BOOL),

    'url' => env('HOOTERY_URL'),

    'token' => env('HOOTERY_TOKEN'),

    /*
    | Request paths whose request events are never sent, as patterns for
    | Request::is(). An exception during such a request is still sent. Keep
    | them narrow: a broad pattern silently drops real pages.
    */

    'ignore_paths' => [
        '_boost/*',
        'up',
    ],

    /*
    | The post is sent after the response (under PHP-FPM the visitor already
    | has the page), but the worker that served the page waits for it. These
    | budgets cap that wait: the connect timeout bounds an unreachable
    | server, the timeout a slow one.
    */

    'connect_timeout' => (float) env('HOOTERY_CONNECT_TIMEOUT', 0.2),

    'timeout' => (float) env('HOOTERY_TIMEOUT', 0.5),

];
