<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Integration;

use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\Order;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

/**
 * Laravel's route:cache, then new application processes that boot from the
 * cached routes, compared with processes that register them again.
 */
final class RouteCacheTest extends TestCase
{
    public function test_cached_routes_keep_the_macro_middleware_names_binding_and_auth(): void
    {
        $cache = $this->temporaryFile('php');
        $env = $this->useBackend('sqlite') + [
            'APP_ROUTES_CACHE' => $cache,
            'IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'),
        ];
        Order::query()->insert(['id' => 7, 'total' => 300]);

        [$uncached] = self::spawn([['mode' => 'routes']], $env);
        $this->assertFalse($uncached['cached']);

        [$cached] = self::spawn([['mode' => 'route:cache']], $env);
        $this->assertSame(0, $cached['status'] ?? null, (string) json_encode($cached));
        $this->assertSame($cache, $cached['file']);
        $this->assertFileExists($cache);

        [$fromCache] = self::spawn([['mode' => 'routes']], $env);
        $this->assertTrue($fromCache['cached']);
        $this->assertSame($uncached['routes'], $fromCache['routes'], 'Cached and registered routes must be identical.');

        $orders = $fromCache['routes']['POST orders'];
        $this->assertSame('orders.store', $orders['name']);
        $this->assertContains(EnsureIdempotency::class, $orders['middleware']);
        $this->assertContains(EnsureIdempotency::class.':600', $fromCache['routes']['POST orders/short']['middleware']);
        $this->assertContains(EnsureIdempotency::class, $fromCache['routes']['POST resources']['middleware']);
        $this->assertSame('orders.refunds', $fromCache['routes']['POST orders/{order}/refunds']['name']);

        // Each request is its own fresh process booted from the cache.
        [$first] = self::spawn([self::job('/orders', ['quantity' => 2])], $env);
        [$replay] = self::spawn([self::job('/orders', ['quantity' => 2])], $env);
        [$conflict] = self::spawn([self::job('/orders', ['quantity' => 3])], $env);
        [$refund] = self::spawn([self::job('/orders/7/refunds', null, 'refund-1')], $env);
        [$anonymous] = self::spawn([self::job('/orders', ['quantity' => 2], 'key-2', 0)], $env);

        foreach ([$first, $replay, $conflict, $refund, $anonymous] as $result) {
            $this->assertTrue($result['cached'] ?? null, (string) json_encode($result));
        }

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $replay['status']);
        $this->assertSame($first['body'], $replay['body']);
        $this->assertSame(['true'], $replay['headers']['idempotency-replayed']);
        $this->assertSame(409, $conflict['status']);
        $this->assertSame('idempotency_key_conflict', json_decode($conflict['body'], true)['code']);
        $this->assertSame(['refunded' => 7, 'total' => 300], json_decode($refund['body'], true));
        $this->assertSame(401, $anonymous['status']);
        $this->assertSame(['store', 'refund'], SideEffects::ledger($ledger));

        // route:clear: registered routes answer exactly like the cached ones did.
        [$cleared] = self::spawn([['mode' => 'artisan', 'command' => 'route:clear']], $env);
        $this->assertSame(0, $cleared['status']);
        $this->assertFileDoesNotExist($cache);

        [$uncachedReplay] = self::spawn([self::job('/orders', ['quantity' => 2])], $env);
        $this->assertFalse($uncachedReplay['cached']);
        $this->assertSame(201, $uncachedReplay['status']);
        $this->assertSame($first['body'], $uncachedReplay['body']);
        $this->assertSame(['true'], $uncachedReplay['headers']['idempotency-replayed']);
        $this->assertSame(['store', 'refund'], SideEffects::ledger($ledger));
    }
}
