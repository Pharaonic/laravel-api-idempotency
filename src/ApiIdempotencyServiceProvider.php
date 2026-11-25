<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\ApiIdempotency\Console\PruneCommand;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;
use Pharaonic\Laravel\ApiIdempotency\Routing\IdempotentMacro;

final class ApiIdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-idempotency.php', 'pharaonic.api-idempotency');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'api-idempotency');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/api-idempotency.php' => $this->app->configPath('pharaonic/api-idempotency.php'),
            ], ['pharaonic', 'laravel-api-idempotency', 'pharaonic-config', 'api-idempotency-config']);

            // Only the database store needs a table, so the migration runs
            // once it is published, never just because the package is installed.
            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], ['pharaonic', 'laravel-api-idempotency', 'pharaonic-migrations', 'api-idempotency-migrations']);

            $this->publishes([
                __DIR__.'/../resources/lang' => lang_path('vendor/api-idempotency'),
            ], ['pharaonic', 'laravel-api-idempotency', 'pharaonic-translations', 'api-idempotency-translations']);

            $this->commands([PruneCommand::class]);
        }

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('idempotent', EnsureIdempotency::class);
        $this->prioritizeMiddleware($router);

        // The HTTP kernel copies its own priority list to the router when it is
        // built; when that happens after boot, apply the order again.
        $this->app->afterResolving('Illuminate\Contracts\Http\Kernel', function () use ($router): void {
            $this->prioritizeMiddleware($router);
        });

        (new IdempotentMacro)->register();

        $this->renderExceptions();
    }

    /**
     * Laravel's handler asks an exception's own render() before its render
     * callbacks, so a refusal is rendered by a callback instead, registered
     * after the application's: an application's own callback, or a package
     * that renders every API error, answers first.
     */
    private function renderExceptions(): void
    {
        $register = static function (mixed $handler): void {
            if (is_object($handler) && method_exists($handler, 'renderable')) {
                $handler->renderable(static fn (IdempotencyException $exception) => $exception->response());
            }
        };

        if ($this->app->resolved(ExceptionHandler::class)) {
            $register($this->app->make(ExceptionHandler::class));

            return;
        }

        $this->app->afterResolving(ExceptionHandler::class, $register);
    }

    /**
     * Run the idempotency middleware after authentication, route model binding
     * and authorization, so the scope is known and refused requests never
     * reserve a key.
     */
    private function prioritizeMiddleware(Router $router): void
    {
        $priority = array_values(array_filter($router->middlewarePriority, static fn ($middleware): bool => $middleware !== EnsureIdempotency::class));
        $position = count($priority);

        foreach (['Illuminate\Auth\Middleware\Authorize', 'Illuminate\Routing\Middleware\SubstituteBindings'] as $anchor) {
            $index = array_search($anchor, $priority, true);

            if ($index !== false) {
                $position = $index + 1;

                break;
            }
        }

        array_splice($priority, $position, 0, [EnsureIdempotency::class]);

        $router->middlewarePriority = $priority;
    }
}
