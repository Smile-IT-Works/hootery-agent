<?php

declare(strict_types=1);

namespace Siw\HooteryAgent;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Siw\HooteryAgent\Console\Commands\TestConnectionCommand;
use Throwable;

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
        // Hooked on the contract, so an app that binds its own kernel class
        // is captured too.
        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernelContract $kernel): void {
            if ($kernel instanceof HttpKernel) {
                $kernel->whenRequestLifecycleIsLongerThan(-1, $this->app->make(HooteryAgent::class)->requestFinished(...));
            }
        });

        Event::listen(RequestHandled::class, fn () => $this->app->make(HooteryAgent::class)->requestHandled());

        // The callback returns nothing: returning false would stop the app's
        // own logging of the exception.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            if ($handler instanceof Handler) {
                $handler->reportable(function (Throwable $exception): void {
                    $this->app->make(HooteryAgent::class)->exceptionReported($exception);
                });
            }
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
