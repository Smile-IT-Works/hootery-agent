<?php

declare(strict_types=1);

namespace Siw\HooteryAgent\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TestConnectionCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'hootery:test';

    /**
     * The command description.
     */
    protected $description = 'Check that this app reaches the Hootery server with its token';

    /**
     * Ask the server which project and environment the token belongs to.
     * Every outcome other than a connection fails, so a deploy script shows
     * it; the script decides whether a failure stops the deploy.
     */
    public function handle(): int
    {
        if (! config()->boolean('hootery.enabled')) {
            $this->error('The agent is switched off (HOOTERY_ENABLED=false).');

            return self::FAILURE;
        }

        $url = (string) config('hootery.url');
        $token = (string) config('hootery.token');

        if ($url === '' || $token === '') {
            $this->error('Set HOOTERY_URL and HOOTERY_TOKEN to connect the agent.');

            return self::FAILURE;
        }

        try {
            // Never redirected: a redirect would carry the token elsewhere.
            $response = Http::acceptJson()
                ->withoutRedirecting()
                ->withToken($token)
                ->connectTimeout(config()->float('hootery.connect_timeout'))
                ->timeout(config()->float('hootery.timeout'))
                ->get(rtrim($url, '/').'/api/ping');
        } catch (ConnectionException $exception) {
            $this->error("Could not reach the Hootery server at {$url}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($response->unauthorized()) {
            $this->error('The server rejected the token. Check HOOTERY_TOKEN.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("The Hootery server answered with HTTP {$response->status()}.");

            return self::FAILURE;
        }

        // Only a Hootery answer counts: a wrong host can answer 200 too.
        if (! is_string($response->json('project')) || ! is_string($response->json('environment'))) {
            $this->error("The server at {$url} did not answer like a Hootery server (HTTP {$response->status()}).");

            return self::FAILURE;
        }

        $label = $response->json('project').'/'.$response->json('environment');

        $this->info("Connected to {$label}.");

        if ($response->json('environment') !== $this->laravel->environment()) {
            $this->warn("This app runs as {$this->laravel->environment()}, but the token belongs to {$label}.");
        }

        return self::SUCCESS;
    }
}
