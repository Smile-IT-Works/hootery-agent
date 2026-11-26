<?php

declare(strict_types=1);

namespace Siw\HooteryAgent;

use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;
use Siw\HooteryAgent\Console\Commands\TestConnectionCommand;

class HooteryAgentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hootery.php', 'hootery');

        $this->app->singleton(HooteryAgent::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
            $kernel->whenRequestLifecycleIsLongerThan(-1, $this->app->make(HooteryAgent::class)->requestFinished(...));
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/hootery.php' => config_path('hootery.php'),
        ], ['hootery-agent', 'hootery-agent-config']);

        $this->commands([
            TestConnectionCommand::class,
        ]);
    }
}
