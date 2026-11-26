<?php

declare(strict_types=1);

namespace Siw\HooteryAgent;

class HooteryAgent
{
    /**
     * Whether the agent may send anything: it is switched on and knows the
     * server and this environment's token.
     */
    public function isEnabled(): bool
    {
        return config()->boolean('hootery.enabled')
            && filled(config('hootery.url'))
            && filled(config('hootery.token'));
    }
}
