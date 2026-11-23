<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\OrderController;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class MacroTest extends TestCase
{
    public function test_idempotent_returns_the_route_and_keeps_the_fluent_chain(): void
    {
        $route = Route::post('chain', [OrderController::class, 'store']);

        $this->assertSame($route, $route->idempotent());
        $this->assertSame($route, $route->idempotent()->name('chain')->middleware('throttle:60,1'));
        $this->assertSame('chain', $route->getName());
    }

    public function test_it_adds_the_middleware_as_a_plain_string(): void
    {
        $this->assertSame([EnsureIdempotency::class], Route::post('a', [OrderController::class, 'store'])->idempotent()->middleware());
        $this->assertSame([EnsureIdempotency::class.':600'], Route::post('b', [OrderController::class, 'store'])->idempotent(600)->middleware());
        $this->assertSame([EnsureIdempotency::class.':604800'], Route::post('c', [OrderController::class, 'store'])->idempotent(ttl: 604800)->middleware());
    }

    public function test_it_works_on_resource_registrations(): void
    {
        Route::apiResource('things', OrderController::class)->idempotent(ttl: 60);
        $routes = $this->app['router']->getRoutes();
        $routes->refreshNameLookups();

        foreach (['things.index', 'things.store', 'things.show', 'things.update', 'things.destroy'] as $name) {
            $route = $routes->getByName($name);
            $this->assertInstanceOf(LaravelRoute::class, $route);
            $this->assertContains(EnsureIdempotency::class.':60', $route->middleware(), $name);
        }
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function invalidArguments(): array
    {
        return [
            'zero ttl' => [0, 'The idempotency route ttl must be a whole number of seconds from 1 to 31536000.'],
            'negative ttl' => [-5, 'The idempotency route ttl must be a whole number of seconds from 1 to 31536000.'],
            'ttl over a year' => [31536001, 'The idempotency route ttl must be a whole number of seconds from 1 to 31536000.'],
        ];
    }

    /**
     * @dataProvider invalidArguments
     */
    public function test_invalid_arguments_fail_when_the_route_is_defined(int $ttl, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Route::post('invalid-route', [OrderController::class, 'store'])->idempotent($ttl);
    }

    public function test_the_longest_and_shortest_ttls_are_accepted(): void
    {
        $this->assertSame([EnsureIdempotency::class.':1'], Route::post('a', [OrderController::class, 'store'])->idempotent(1)->middleware());
        $this->assertSame([EnsureIdempotency::class.':31536000'], Route::post('b', [OrderController::class, 'store'])->idempotent(31536000)->middleware());
    }

    public function test_a_malformed_ttl_parameter_written_by_hand_is_rejected(): void
    {
        Route::post('hand-written', [OrderController::class, 'store'])->middleware(['auth:fixture', 'idempotent:ten']);
        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The idempotency route ttl must be a whole number of seconds from 1 to 31536000.');

        $this->idempotent('/hand-written');
    }
}
