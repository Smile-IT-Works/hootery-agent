<?php

declare(strict_types=1);

namespace Siw\HooteryAgent\Console\Commands;

use Illuminate\Console\Command;

class HooteryAgentCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'hootery-agent:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package hootery-agent.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('HooteryAgent placeholder command executed.');

        return self::SUCCESS;
    }
}
