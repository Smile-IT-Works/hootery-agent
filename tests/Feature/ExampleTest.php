<?php

declare(strict_types=1);

use Siw\HooteryAgent\HooteryAgent;

it('resolves the singleton', function () {
    expect(app(HooteryAgent::class))->toBeInstanceOf(HooteryAgent::class);
});

it('returns the same instance from the container', function () {
    expect(app(HooteryAgent::class))->toBe(app(HooteryAgent::class));
});

it('registers the artisan command', function () {
    $this->artisan('hootery-agent:placeholder')
        ->expectsOutputToContain('HooteryAgent placeholder command executed.')
        ->assertSuccessful();
});
