<div align="center">
    <h1>Hootery Agent</h1>
</div>

Captures requests and exceptions and sends them to a Hootery cloud.

## Installation

The package is private, so Composer fetches it from its GitHub repository. Add the repository to the app's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:Smile-IT-Works/hootery-agent.git"
    }
]
```

Then require it:

```bash
composer require siw/hootery-agent:dev-main
```

The machine that runs `composer install` (a developer's laptop, a deploy server) needs read access to that repository.

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="hootery-agent"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="hootery-agent-config"
```

## Configuration

Set the server and this environment's token in the app's `.env`. The token is issued on the Hootery server with `php artisan hootery:token {project} {environment}`.

```dotenv
HOOTERY_URL=https://hootery.example.com
HOOTERY_TOKEN=1|xxxxxxxx
```

| Variable | Default | Meaning |
| --- | --- | --- |
| `HOOTERY_ENABLED` | `true` | Switches the agent off for this environment when `false`. |
| `HOOTERY_URL` | none | Base URL of the Hootery server. |
| `HOOTERY_TOKEN` | none | The token issued for this project and environment. |
| `HOOTERY_CONNECT_TIMEOUT` | `0.2` | Seconds to reach the server before the post is given up. |
| `HOOTERY_TIMEOUT` | `0.5` | Seconds the whole post may take before it is given up. |

The agent stays silent until it has both a URL and a token, so installing the package alone never affects the app.

To skip requests that are noise, list their paths in `ignore_paths` in the published `config/hootery.php`, as patterns for `Request::is()` (the default skips Laravel Boost's dev tool, `_boost/*`, and Laravel's health check, `up`). Only the request event is skipped: an exception during such a request is still sent. Keep the patterns narrow, since a broad one silently drops real pages.

The agent posts each request's events after the response is sent. Under PHP-FPM (and LiteSpeed) the visitor already has the page by then; under Apache mod_php or `php artisan serve` the connection can stay open until the post is done. Either way the PHP worker that served the page waits, for up to the timeouts above. A failed or slow post is dropped, never retried. On a busy production site, keep the timeouts low: until the agent batches its posts, a struggling Hootery server costs every page view up to that much worker time.

Check the connection:

```bash
php artisan hootery:test
```

It prints the project and environment the token belongs to, warns when that environment differs from the app's `APP_ENV`, and exits with a failure code when the agent is off, not set up, rejected or unreachable. Add it to each environment's deploy script, so a wrong `HOOTERY_URL` or `HOOTERY_TOKEN` fails the deploy instead of leaving Hootery quietly empty.

Once deployed, any post that does not arrive (refused, unreachable, or failing for another reason) is logged in the app's own log as `Hootery agent could not deliver events`, with the URL, status and error, at most once every ten minutes.

### Testing your app

Laravel runs the request lifecycle in feature tests too, so with `HOOTERY_URL` and `HOOTERY_TOKEN` in the `.env` every test request would post to the server. Switch the agent off in the app's `phpunit.xml`, next to the Pulse and Nightwatch switches:

```xml
<env name="HOOTERY_ENABLED" value="false"/>
```

## What the agent captures

Nothing needs calling: once configured, the agent hooks into Laravel's HTTP kernel and exception handler.

**Requests.** After every response is sent, one `request` event: method, the route pattern as `path` (`/tournaments/{tournament}`, so all visits to one page group together), status code, duration (until the app has its response, so the work after it is left out) and start time (UTC, microseconds). The payload adds the real path (`/tournaments/17`), the route name and action, and the request's peak memory. The query string is never sent, and the value of a route parameter whose name looks secret (`token`, `signature`, `secret`, `hash`, `password`, `key`) is replaced by its name, so a password-reset link arrives as `/reset-password/{token}`. Paths are cut at 2048 characters.

**Exceptions.** Every exception the app reports, as an `exception` event: class, message (up to 65 535 characters), code, file and line, the PHP and Laravel versions, and a trace of up to 50 frames naming places and calls only, never argument values. Paths are relative to the app. An exception thrown while rendering a view is unwrapped to its real cause. A fatal error (out of memory, a time limit) is sent at once, since the request it ends never finishes.

A request and the exceptions reported during it share one `trace_id` and travel to the server in the same post.

### Limits

- **Exceptions outside web requests are not delivered yet.** Queue workers and console commands have no request end to send at, so the agent records nothing there. In a web request it holds at most 100 events before the send.
- **Exceptions the app does not report are not captured**: those on the handler's don't-report list, those whose own `report()` method handles them, and those an earlier `reportable` callback stops by returning `false`.
- **Exception messages are sent as they are.** A database error's message contains its SQL with the bound values, which can include personal data. It goes only to your own Hootery server, but keep it in mind.
- **Livewire interactions all post to one URL**, so they group as a single route for now.
- **A request that never finishes normally** (a terminate middleware that throws, or under Octane an exception reported between requests) can leave events behind that ride along with the next request's post.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Hootery Agent! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Pavol Bucko](https://github.com/pbucko1)
- [All Contributors](../../contributors)

## License

Proprietary. All rights reserved.
