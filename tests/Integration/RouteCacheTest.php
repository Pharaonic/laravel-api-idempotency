<?php

declare(strict_types=1);

use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\Order;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;

/**
 * Laravel's route:cache, then new application processes that boot from the
 * cached routes, compared with processes that register them again.
 */
test('cached routes keep the macro middleware names binding and auth', function () {
    // Relative to the base path: Laravel 6 reads only paths starting with "/"
    // as absolute, so a Windows temporary path would be appended to it.
    $relative = 'bootstrap/cache/idempotency-routes-'.bin2hex(random_bytes(6)).'.php';
    $cache = $this->app->basePath($relative);
    $this->beforeApplicationDestroyed(static function () use ($cache): void {
        if (is_file($cache)) {
            @unlink($cache);
        }
    });
    $env = $this->useBackend('sqlite') + [
        'APP_ROUTES_CACHE' => $relative,
        'IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'),
    ];
    Order::query()->insert(['id' => 7, 'total' => 300]);

    [$uncached] = self::spawn([['mode' => 'routes']], $env);
    expect($uncached['cached'])->toBeFalse();

    [$cached] = self::spawn([['mode' => 'route:cache']], $env);
    $this->assertSame(0, $cached['status'] ?? null, (string) json_encode($cached));
    expect($cached['file'])->toBe($cache);
    expect($cache)->toBeFile();

    [$fromCache] = self::spawn([['mode' => 'routes']], $env);
    expect($fromCache['cached'])->toBeTrue();
    $this->assertSame($uncached['routes'], $fromCache['routes'], 'Cached and registered routes must be identical.');

    $orders = $fromCache['routes']['POST orders'];
    expect($orders['name'])->toBe('orders.store');
    expect($orders['middleware'])->toContain(EnsureIdempotency::class);
    expect($fromCache['routes']['POST orders/short']['middleware'])->toContain(EnsureIdempotency::class.':600');
    expect($fromCache['routes']['POST resources']['middleware'])->toContain(EnsureIdempotency::class);
    expect($fromCache['routes']['POST orders/{order}/refunds']['name'])->toBe('orders.refunds');

    // Each request is its own fresh process booted from the cache.
    [$first] = self::spawn([self::job('/orders', ['quantity' => 2])], $env);
    [$replay] = self::spawn([self::job('/orders', ['quantity' => 2])], $env);
    [$conflict] = self::spawn([self::job('/orders', ['quantity' => 3])], $env);
    [$refund] = self::spawn([self::job('/orders/7/refunds', null, 'refund-1')], $env);
    [$anonymous] = self::spawn([self::job('/orders', ['quantity' => 2], 'key-2', 0)], $env);

    foreach ([$first, $replay, $conflict, $refund, $anonymous] as $result) {
        $this->assertTrue($result['cached'] ?? null, (string) json_encode($result));
    }

    expect($first['status'])->toBe(201);
    expect($replay['status'])->toBe(201);
    expect($replay['body'])->toBe($first['body']);
    expect($replay['headers']['idempotency-replayed'])->toBe(['true']);
    expect($conflict['status'])->toBe(409);
    expect(json_decode($conflict['body'], true)['code'])->toBe('idempotency_key_conflict');
    expect(json_decode($refund['body'], true))->toBe(['refunded' => 7, 'total' => 300]);
    expect($anonymous['status'])->toBe(401);
    expect(SideEffects::ledger($ledger))->toBe(['store', 'refund']);

    // route:clear: registered routes answer exactly like the cached ones did.
    [$cleared] = self::spawn([['mode' => 'artisan', 'command' => 'route:clear']], $env);
    expect($cleared['status'])->toBe(0);
    expect($cache)->not->toBeFile();

    [$uncachedReplay] = self::spawn([self::job('/orders', ['quantity' => 2])], $env);
    expect($uncachedReplay['cached'])->toBeFalse();
    expect($uncachedReplay['status'])->toBe(201);
    expect($uncachedReplay['body'])->toBe($first['body']);
    expect($uncachedReplay['headers']['idempotency-replayed'])->toBe(['true']);
    expect(SideEffects::ledger($ledger))->toBe(['store', 'refund']);
});
