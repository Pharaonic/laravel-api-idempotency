<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Pharaonic\Laravel\ApiIdempotency\ApiIdempotencyServiceProvider;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_it_registers_the_service_provider(): void
    {
        $this->assertNotEmpty($this->app->getProviders(ApiIdempotencyServiceProvider::class));
    }

    public function test_it_merges_the_package_configuration(): void
    {
        $this->assertSame(86400, config('pharaonic.api-idempotency.ttl'));
        $this->assertSame(120, config('pharaonic.api-idempotency.lease'));
        $this->assertSame('database', config('pharaonic.api-idempotency.store.driver'));
        $this->assertSame('idempotency_keys', config('pharaonic.api-idempotency.store.table'));
        $this->assertSame(1048576, config('pharaonic.api-idempotency.max_response_bytes'));
    }

    public function test_it_registers_the_middleware_alias(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $this->assertSame(EnsureIdempotency::class, $router->getMiddleware()['idempotent']);
    }

    public function test_it_runs_the_middleware_after_authentication_binding_and_authorization(): void
    {
        $this->app->make(Kernel::class);
        /** @var Router $router */
        $router = $this->app->make('router');
        $priority = $router->middlewarePriority;
        $position = array_search(EnsureIdempotency::class, $priority, true);

        $this->assertIsInt($position);
        $this->assertSame(1, count(array_keys($priority, EnsureIdempotency::class, true)));

        foreach (['Illuminate\Auth\Middleware\Authenticate', 'Illuminate\Routing\Middleware\SubstituteBindings', 'Illuminate\Auth\Middleware\Authorize'] as $before) {
            $index = array_search($before, $priority, true);

            if ($index !== false) {
                $this->assertLessThan($position, $index, $before.' must run before the idempotency middleware.');
            }
        }
    }

    public function test_it_registers_the_prune_command(): void
    {
        $this->assertArrayHasKey('idempotency:prune', Artisan::all());
    }

    public function test_it_does_not_run_its_migration_until_it_is_published(): void
    {
        $paths = array_map('realpath', $this->app['migrator']->paths());

        $this->assertNotContains(realpath(__DIR__.'/../../database/migrations'), $paths);
    }

    public function test_it_publishes_the_configuration_and_migration_under_the_pharaonic_tags(): void
    {
        foreach (['pharaonic', 'laravel-api-idempotency', 'pharaonic-config', 'api-idempotency-config'] as $tag) {
            $this->assertContains($this->app->configPath('pharaonic/api-idempotency.php'), ApiIdempotencyServiceProvider::pathsToPublish(ApiIdempotencyServiceProvider::class, $tag), $tag);
        }

        foreach (['pharaonic', 'laravel-api-idempotency', 'pharaonic-migrations', 'api-idempotency-migrations'] as $tag) {
            $this->assertContains($this->app->databasePath('migrations'), ApiIdempotencyServiceProvider::pathsToPublish(ApiIdempotencyServiceProvider::class, $tag), $tag);
        }
    }
}
