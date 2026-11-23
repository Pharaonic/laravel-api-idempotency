<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\Order;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;

test('the first request runs the route once and a retry replays it', function () {
    $first = $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 2]);
    $first->assertStatus(201);
    $first->assertExactJson(['id' => 1, 'status' => 'created', 'user' => 1, 'input' => ['product_id' => 15, 'quantity' => 2]]);
    expect($first->headers->get('Idempotency-Replayed'))->toBeNull();

    $second = $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 2]);
    $second->assertStatus(201);
    expect($second->getContent())->toBe($first->getContent());
    expect($second->headers->get('Idempotency-Replayed'))->toBe('true');
    expect($second->headers->get('Location'))->toBe('/orders/1');
    expect($second->headers->get('Content-Type'))->toBe('application/json');
    expect(SideEffects::$count)->toBe(1);
});

test('a replay never carries cookies or unlisted headers', function () {
    $first = $this->idempotent('/orders', ['quantity' => 2]);
    expect($first->headers->getCookies())->not->toBeEmpty();
    expect($first->headers->get('X-Debug-Token'))->toBe('private-1');

    $second = $this->idempotent('/orders', ['quantity' => 2]);
    expect($second->headers->getCookies())->toBe([]);
    expect($second->headers->get('Set-Cookie'))->toBeNull();
    expect($second->headers->get('X-Debug-Token'))->toBeNull();
});

test('json object key order does not matter', function () {
    $this->raw('/orders', '{"product_id":15,"quantity":2}', ['Content-Type' => 'application/json'])->assertStatus(201);
    $replay = $this->raw('/orders', '{ "quantity": 2, "product_id": 15 }', ['Content-Type' => 'application/json']);

    $replay->assertStatus(201);
    expect($replay->headers->get('Idempotency-Replayed'))->toBe('true');
    expect(SideEffects::$count)->toBe(1);
});

test('the same key with another body is a conflict', function () {
    $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 2])->assertStatus(201);

    $conflict = $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 5]);

    $conflict->assertStatus(409);
    $conflict->assertExactJson(['message' => 'This Idempotency-Key was already used with a different request.', 'code' => 'idempotency_key_conflict']);
    expect(SideEffects::$count)->toBe(1);
});

test('types and list order are part of the fingerprint', function () {
    $this->idempotent('/orders', ['quantity' => 2, 'tags' => ['a', 'b']])->assertStatus(201);

    $this->idempotent('/orders', ['quantity' => '2', 'tags' => ['a', 'b']])->assertStatus(409);
    $this->raw('/orders', '{"quantity":2.0,"tags":["a","b"]}', ['Content-Type' => 'application/json'])->assertStatus(409);
    $this->idempotent('/orders', ['quantity' => 2, 'tags' => ['b', 'a']])->assertStatus(409);
    $this->raw('/orders', '{"quantity":2,"tags":["a","b"],"extra":{}}', ['Content-Type' => 'application/json'])->assertStatus(409);
    expect(SideEffects::$count)->toBe(1);
});

test('the query string is part of the fingerprint', function () {
    $this->idempotent('/orders?coupon=A', ['quantity' => 2])->assertStatus(201);

    $this->idempotent('/orders?coupon=B', ['quantity' => 2])->assertStatus(409);
    $this->idempotent('/orders?coupon=A', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');
});

test('route parameters are part of the fingerprint', function () {
    Order::query()->insert([['id' => 1, 'total' => 100], ['id' => 2, 'total' => 250]]);

    $this->idempotent('/orders/1/refunds')->assertStatus(201)->assertExactJson(['refunded' => 1, 'total' => 100]);

    $this->idempotent('/orders/2/refunds')->assertStatus(409)->assertJson(['code' => 'idempotency_key_conflict']);
    $this->idempotent('/orders/1/refunds')->assertHeader('Idempotency-Replayed', 'true');
    expect(SideEffects::$count)->toBe(1);
});

test('other routes and methods have their own keys', function () {
    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201)->assertJson(['id' => 1]);
    $this->idempotent('/orders/copy', ['quantity' => 2])->assertStatus(201)->assertJson(['id' => 2]);
    $this->idempotent('/orders', ['quantity' => 2], 'key-1', 1, [], 'PUT')->assertStatus(201)->assertJson(['id' => 3]);

    expect(SideEffects::$count)->toBe(3);
});

test('users never share keys or responses', function () {
    $alice = $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 1);
    $bob = $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 2);

    $alice->assertStatus(201)->assertJson(['id' => 1, 'user' => 1]);
    $bob->assertStatus(201)->assertJson(['id' => 2, 'user' => 2]);
    expect($bob->headers->get('Idempotency-Replayed'))->toBeNull();

    $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 2)->assertJson(['id' => 2, 'user' => 2])->assertHeader('Idempotency-Replayed', 'true');
    $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 1)->assertJson(['id' => 1, 'user' => 1])->assertHeader('Idempotency-Replayed', 'true');
    expect(SideEffects::$count)->toBe(2);
});

test('sequential requests in one application keep their own context', function () {
    // Like an Octane worker: one application instance serves every request.
    foreach ([[1, 'a'], [2, 'a'], [1, 'b'], [2, 'b']] as [$user, $key]) {
        $this->idempotent('/orders', ['quantity' => 1], $key, $user)->assertStatus(201)->assertJson(['user' => $user]);
    }

    foreach ([[2, 'b', 4], [1, 'a', 1], [2, 'a', 2], [1, 'b', 3]] as [$user, $key, $id]) {
        $this->idempotent('/orders', ['quantity' => 1], $key, $user)->assertJson(['id' => $id, 'user' => $user])->assertHeader('Idempotency-Replayed', 'true');
    }

    expect(SideEffects::$count)->toBe(4);
});

test('an empty 204 is replayed', function () {
    $this->idempotent('/no-content')->assertStatus(204);
    $replay = $this->idempotent('/no-content');

    $replay->assertStatus(204);
    expect($replay->getContent())->toBe('');
    expect($replay->headers->get('Idempotency-Replayed'))->toBe('true');
    expect(SideEffects::$count)->toBe(1);
});

test('requests without a body are fingerprinted too', function () {
    $this->raw('/orders', '')->assertStatus(201);
    $this->raw('/orders', '')->assertHeader('Idempotency-Replayed', 'true');
    $this->raw('/orders', 'null', ['Content-Type' => 'application/json'])->assertStatus(409);
});

test('safe methods pass through without a key', function () {
    $this->getJson('/resources', ['Authorization' => 'Bearer user-1'])->assertOk()->assertExactJson(['data' => []]);
    $this->getJson('/resources/7', ['Authorization' => 'Bearer user-1'])->assertOk();

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

test('the route ttl reaches the middleware', function () {
    $this->idempotent('/orders/short', ['quantity' => 2])->assertStatus(201);

    $record = DB::table('idempotency_keys')->first();
    expect($record)->not->toBeNull();
    expect((int) $record->expires_at)->toBe(self::START + 600);
});

test('the middleware alias works on groups', function () {
    $this->idempotent('/aliased', ['quantity' => 2])->assertStatus(201);
    $this->idempotent('/aliased', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');

    $record = DB::table('idempotency_keys')->first();
    expect($record)->not->toBeNull();
    expect((int) $record->expires_at)->toBe(self::START + 600);
});

test('api resources protect their unsafe actions', function () {
    $this->idempotent('/resources', [])->assertStatus(201);
    $this->idempotent('/resources', [])->assertHeader('Idempotency-Replayed', 'true');
    $this->idempotent('/resources/5', ['name' => 'x'], 'key-2', 1, [], 'PATCH')->assertOk()->assertJson(['updates' => 2]);
    $this->idempotent('/resources/5', ['name' => 'x'], 'key-2', 1, [], 'PATCH')->assertHeader('Idempotency-Replayed', 'true');
    $this->idempotent('/resources/5', [], 'key-3', 1, [], 'DELETE')->assertOk()->assertJson(['deletes' => 3]);
    $this->idempotent('/resources/5', [], null, 1, [], 'DELETE')->assertStatus(400);

    expect(SideEffects::$count)->toBe(3);
});

test('a custom scope resolver can scope anonymous clients', function () {
    config(['pharaonic.api-idempotency.scope' => \Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\TenantScope::class]);

    $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0, ['X-Tenant' => 'acme'])->assertStatus(201)->assertJson(['id' => 1]);
    $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0, ['X-Tenant' => 'globex'])->assertStatus(201)->assertJson(['id' => 2]);
    $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0, ['X-Tenant' => 'acme'])->assertJson(['id' => 1])->assertHeader('Idempotency-Replayed', 'true');
    $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0)->assertStatus(401)->assertJson(['code' => 'idempotency_unauthenticated']);
});

test('the storage never holds the raw key identity or request body', function () {
    $this->idempotent('/orders', ['card' => '4242424242424242'], 'customer-secret-key', 1);

    $record = (array) DB::table('idempotency_keys')->first();
    $stored = json_encode(array_diff_key($record, ['response_body' => true]));

    expect($record['storage_key'])->toMatch('/\A[0-9a-f]{64}\z/');
    expect($record['request_fingerprint'])->toMatch('/\A[0-9a-f]{64}\z/');
    expect((string) $stored)->not->toContain('customer-secret-key');
    expect((string) $stored)->not->toContain('4242424242424242');
    expect((string) $stored)->not->toContain('GenericUser');
    expect($record['route'])->toBe('/orders');
});
