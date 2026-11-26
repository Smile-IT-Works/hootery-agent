<?php

declare(strict_types=1);

use Siw\HooteryAgent\HooteryAgent;

it('binds one agent, so exceptions and the request they happen in share a buffer', function () {
    expect(app(HooteryAgent::class))->toBe(app(HooteryAgent::class));
});
