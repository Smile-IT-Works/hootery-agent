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
     * Every outcome other than a connection fails, so the command can guard a
     * deploy script.
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
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout(config()->float('hootery.timeout'))
                ->get(rtrim($url, '/').'/api/ping');
        } catch (ConnectionException) {
            $this->error("Could not reach the Hootery server at {$url}.");

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

        $label = $response->json('project').'/'.$response->json('environment');

        $this->info("Connected to {$label}.");

        if ($response->json('environment') !== $this->laravel->environment()) {
            $this->warn("This app runs as {$this->laravel->environment()}, but the token belongs to {$label}.");
        }

        return self::SUCCESS;
    }
}
