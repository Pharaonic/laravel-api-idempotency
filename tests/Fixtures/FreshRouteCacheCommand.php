<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Foundation\Console\RouteCacheCommand;

/**
 * Laravel's route:cache, building its fresh application like the fixture
 * application instead of from Testbench's bootstrap/app.php, which does not
 * load the package or its routes. Everything else is the framework command.
 */
final class FreshRouteCacheCommand extends RouteCacheCommand
{
    /**
     * @return \Illuminate\Foundation\Application
     */
    protected function getFreshApplication()
    {
        return (new Harness('fresh'))->createApplication();
    }
}
