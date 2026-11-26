<div align="center">
    <h1>Hootery Agent</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/siw/hootery-agent"><img src="https://img.shields.io/packagist/v/siw/hootery-agent.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/siw/hootery-agent"><img src="https://img.shields.io/packagist/php-v/siw/hootery-agent.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/siw/hootery-agent"><img src="https://badge.laravel.cloud/badge/siw/hootery-agent?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/Smile-IT-Works/hootery-agent/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/Smile-IT-Works/hootery-agent/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/siw/hootery-agent"><img src="https://img.shields.io/packagist/dt/siw/hootery-agent.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Captures requests and exceptions and sends them to a Hootery cloud.

## Installation

You can install the package via Composer:

```bash
composer require siw/hootery-agent
```

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
| `HOOTERY_TIMEOUT` | `1.0` | Seconds one post to the server may take before it is given up. |

The agent stays silent until it has both a URL and a token, so installing the package alone never affects the app.

Check the connection:

```bash
php artisan hootery:test
```

It prints the project and environment the token belongs to, warns when that environment differs from the app's `APP_ENV`, and exits with a failure code when the agent is off, not set up, rejected or unreachable, so it can guard a deploy script.

## Usage

<!-- Add a basic usage example here. -->

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

Hootery Agent is open-sourced software licensed under the [MIT license](LICENSE.md).
