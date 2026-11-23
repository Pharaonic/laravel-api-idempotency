<?php

declare(strict_types=1);

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\OrderController;

test('idempotent returns the route and keeps the fluent chain', function () {
    $route = Route::post('chain', [OrderController::class, 'store']);

    expect($route->idempotent())->toBe($route);
    expect($route->idempotent()->name('chain')->middleware('throttle:60,1'))->toBe($route);
    expect($route->getName())->toBe('chain');
});

test('it adds the middleware as a plain string', function () {
    expect(Route::post('a', [OrderController::class, 'store'])->idempotent()->middleware())->toBe([EnsureIdempotency::class]);
    expect(Route::post('b', [OrderController::class, 'store'])->idempotent(600)->middleware())->toBe([EnsureIdempotency::class.':600']);
    expect(Route::post('c', [OrderController::class, 'store'])->idempotent(ttl: 604800)->middleware())->toBe([EnsureIdempotency::class.':604800']);
});

test('it works on resource registrations', function () {
    Route::apiResource('things', OrderController::class)->idempotent(ttl: 60);
    $routes = $this->app['router']->getRoutes();
    $routes->refreshNameLookups();

    foreach (['things.index', 'things.store', 'things.show', 'things.update', 'things.destroy'] as $name) {
        $route = $routes->getByName($name);
        expect($route)->toBeInstanceOf(LaravelRoute::class);
        $this->assertContains(EnsureIdempotency::class.':60', $route->middleware(), $name);
    }
});

dataset('invalidArguments', function () {
    return [
        'zero ttl' => [0, 'The idempotency route ttl must be a whole number of seconds from 1 to 31536000.'],
        'negative ttl' => [-5, 'The idempotency route ttl must be a whole number of seconds from 1 to 31536000.'],
        'ttl over a year' => [31536001, 'The idempotency route ttl must be a whole number of seconds from 1 to 31536000.'],
    ];
});

test('invalid arguments fail when the route is defined', function (int $ttl, string $message) {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    Route::post('invalid-route', [OrderController::class, 'store'])->idempotent($ttl);
})->with('invalidArguments');

test('the longest and shortest ttls are accepted', function () {
    expect(Route::post('a', [OrderController::class, 'store'])->idempotent(1)->middleware())->toBe([EnsureIdempotency::class.':1']);
    expect(Route::post('b', [OrderController::class, 'store'])->idempotent(31536000)->middleware())->toBe([EnsureIdempotency::class.':31536000']);
});

test('a malformed ttl parameter written by hand is rejected', function () {
    Route::post('hand-written', [OrderController::class, 'store'])->middleware(['auth:fixture', 'idempotent:ten']);
    $this->withoutExceptionHandling();
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('The idempotency route ttl must be a whole number of seconds from 1 to 31536000.');

    $this->idempotent('/hand-written');
});
