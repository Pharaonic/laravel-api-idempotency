<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Pharaonic\Laravel\ApiIdempotency\ApiIdempotencyServiceProvider;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;

test('it registers the service provider', function () {
    expect($this->app->getProviders(ApiIdempotencyServiceProvider::class))->not->toBeEmpty();
});

test('it merges the package configuration', function () {
    expect(config('pharaonic.api-idempotency.ttl'))->toBe(86400);
    expect(config('pharaonic.api-idempotency.lease'))->toBe(120);
    expect(config('pharaonic.api-idempotency.store.driver'))->toBe('database');
    expect(config('pharaonic.api-idempotency.store.table'))->toBe('idempotency_keys');
    expect(config('pharaonic.api-idempotency.max_response_bytes'))->toBe(1048576);
});

test('it registers the middleware alias', function () {
    /** @var Router $router */
    $router = $this->app->make('router');

    expect($router->getMiddleware()['idempotent'])->toBe(EnsureIdempotency::class);
});

test('it runs the middleware after authentication binding and authorization', function () {
    $this->app->make(Kernel::class);
    /** @var Router $router */
    $router = $this->app->make('router');
    $priority = $router->middlewarePriority;
    $position = array_search(EnsureIdempotency::class, $priority, true);

    expect($position)->toBeInt();
    expect(count(array_keys($priority, EnsureIdempotency::class, true)))->toBe(1);

    foreach (['Illuminate\Auth\Middleware\Authenticate', 'Illuminate\Routing\Middleware\SubstituteBindings', 'Illuminate\Auth\Middleware\Authorize'] as $before) {
        $index = array_search($before, $priority, true);

        if ($index !== false) {
            $this->assertLessThan($position, $index, $before.' must run before the idempotency middleware.');
        }
    }
});

test('it registers the prune command', function () {
    expect(Artisan::all())->toHaveKey('idempotency:prune');
});

test('it does not run its migration until it is published', function () {
    $paths = array_map('realpath', $this->app['migrator']->paths());

    expect($paths)->not->toContain(realpath(__DIR__.'/../../database/migrations'));
});

test('it publishes the configuration and migration under the pharaonic tags', function () {
    foreach (['pharaonic', 'laravel-api-idempotency', 'pharaonic-config', 'api-idempotency-config'] as $tag) {
        $this->assertContains($this->app->configPath('pharaonic/api-idempotency.php'), ApiIdempotencyServiceProvider::pathsToPublish(ApiIdempotencyServiceProvider::class, $tag), $tag);
    }

    foreach (['pharaonic', 'laravel-api-idempotency', 'pharaonic-migrations', 'api-idempotency-migrations'] as $tag) {
        $this->assertContains($this->app->databasePath('migrations'), ApiIdempotencyServiceProvider::pathsToPublish(ApiIdempotencyServiceProvider::class, $tag), $tag);
    }
});
