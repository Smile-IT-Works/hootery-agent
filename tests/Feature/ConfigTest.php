<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Siw\HooteryAgent\HooteryAgent;
use Siw\HooteryAgent\HooteryAgentServiceProvider;

it('merges the agent config under the hootery key with safe defaults', function () {
    expect(config('hootery'))->toBe([
        'enabled' => true,
        'url' => null,
        'token' => null,
        'ignore_paths' => ['_boost/*', 'up'],
        'connect_timeout' => 0.2,
        'timeout' => 0.5,
    ]);
});

it('publishes the config file as config/hootery.php', function (string $tag) {
    // Checked on the mapping, not by publishing: a file written into the
    // skeleton's config folder is loaded by every parallel worker.
    $paths = ServiceProvider::pathsToPublish(HooteryAgentServiceProvider::class, $tag);

    expect($paths)->toHaveCount(1)
        ->and(realpath(array_key_first($paths)))->toBe(realpath(dirname(__DIR__, 2).'/config/hootery.php'))
        ->and(array_values($paths))->toBe([config_path('hootery.php')]);
})->with(['hootery-agent', 'hootery-agent-config']);

it('is enabled once switched on with a url and a token', function () {
    config(['hootery.url' => 'https://hootery.test', 'hootery.token' => '1|secret']);

    expect(app(HooteryAgent::class)->isEnabled())->toBeTrue();
});

it('stays silent without a url, without a token or when switched off', function (array $overrides) {
    config(['hootery.url' => 'https://hootery.test', 'hootery.token' => '1|secret', ...$overrides]);

    expect(app(HooteryAgent::class)->isEnabled())->toBeFalse();
})->with([
    'no url' => [['hootery.url' => null]],
    'empty url' => [['hootery.url' => '']],
    'no token' => [['hootery.token' => null]],
    'empty token' => [['hootery.token' => '']],
    'switched off' => [['hootery.enabled' => false]],
]);

it('reads the usual ways of writing on and off in HOOTERY_ENABLED', function (string $value, bool $enabled) {
    $_SERVER['HOOTERY_ENABLED'] = $_ENV['HOOTERY_ENABLED'] = $value;

    $config = require dirname(__DIR__, 2).'/config/hootery.php';

    unset($_SERVER['HOOTERY_ENABLED'], $_ENV['HOOTERY_ENABLED']);
    expect($config['enabled'])->toBe($enabled);
})->with([
    ['true', true], ['1', true], ['on', true], ['yes', true],
    ['false', false], ['0', false], ['off', false], ['no', false],
]);
