<?php

declare(strict_types=1);

namespace Siw\HooteryAgent\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Siw\HooteryAgent\HooteryAgentServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            HooteryAgentServiceProvider::class,
        ];
    }
}
