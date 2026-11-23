<?php

declare(strict_types=1);

test('a missing key is rejected before the route runs', function () {
    $this->idempotent('/orders', ['quantity' => 2], null)
        ->assertStatus(400)
        ->assertExactJson(['message' => 'The Idempotency-Key header is required.', 'code' => 'idempotency_key_missing']);

    $this->idempotent('/orders', ['quantity' => 2], '')->assertStatus(400)->assertJson(['code' => 'idempotency_key_missing']);
    $this->assertNothingRan();
});

dataset('invalidKeys', function () {
    return [
        'too long' => [str_repeat('k', 256)],
        'space' => ['order 123'],
        'non-ascii' => ['طلب-123'],
        'control character' => ["order\t123"],
    ];
});

test('a malformed key is rejected before the route runs', function (string $key) {
    $this->idempotent('/orders', ['quantity' => 2], $key)
        ->assertStatus(400)
        ->assertExactJson(['message' => 'The Idempotency-Key header must be one value of 1 to 255 visible ASCII characters.', 'code' => 'idempotency_key_invalid']);

    $this->assertNothingRan();
})->with('invalidKeys');

test('keys of 1 and 255 visible characters are accepted', function () {
    $this->idempotent('/orders', ['quantity' => 2], '7')->assertStatus(201);
    $this->idempotent('/orders', ['quantity' => 2], str_repeat('~', 255))->assertStatus(201);
    $this->idempotent('/orders', ['quantity' => 2], '"quoted-uuid"')->assertStatus(201);
});

test('a repeated key header is rejected', function () {
    $server = $this->transformHeadersToServerVars(['Authorization' => 'Bearer user-1', 'Content-Type' => 'application/json']);
    $request = \Illuminate\Http\Request::create('/orders', 'POST', [], [], [], $server, '{}');
    $request->headers->set('Idempotency-Key', ['a', 'b']);

    $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

    expect($response->getStatusCode())->toBe(400);
    expect((string) $response->getContent())->toContain('idempotency_key_invalid');
    $this->assertNothingRan();
});

test('an unauthenticated request is rejected instead of sharing an anonymous key space', function () {
    $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0)
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Idempotent requests must be authenticated.', 'code' => 'idempotency_unauthenticated']);

    $this->assertNothingRan();
});

test('authentication runs first even when it is added after the macro', function () {
    // auth:fixture is declared after ->idempotent(); the middleware priority still runs it first.
    $this->idempotent('/late-auth', ['quantity' => 2], 'key-1', 0)->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
    $this->idempotent('/late-auth', ['quantity' => 2], 'key-1', 2)->assertStatus(201)->assertJson(['user' => 2]);
    $this->idempotent('/late-auth', ['quantity' => 2], 'key-1', 2)->assertHeader('Idempotency-Replayed', 'true');
});

test('a non json body is unsupported', function () {
    $this->raw('/orders', 'quantity=2', ['Content-Type' => 'application/x-www-form-urlencoded'])
        ->assertStatus(415)
        ->assertExactJson(['message' => 'Idempotent requests must have a JSON body or none.', 'code' => 'idempotency_request_unsupported']);

    $this->raw('/orders', '{"quantity":2}', ['Content-Type' => 'text/plain'])->assertStatus(415);
    $this->assertNothingRan();
});

test('structured json media types are accepted', function () {
    $this->raw('/orders', '{"quantity":2}', ['Content-Type' => 'application/merge-patch+json'])->assertStatus(201);
});

test('invalid json is rejected', function () {
    $this->raw('/orders', '{"quantity":', ['Content-Type' => 'application/json'])
        ->assertStatus(400)
        ->assertExactJson(['message' => 'The request body is not valid JSON or is nested too deeply.', 'code' => 'idempotency_request_invalid']);

    $this->assertNothingRan();
});

test('json nested deeper than json decode allows is rejected', function () {
    $nested = static fn (int $depth): string => str_repeat('[', $depth).str_repeat(']', $depth);

    // json_decode() at its default depth of 512 accepts 511 levels.
    $this->raw('/orders', $nested(511), ['Content-Type' => 'application/json'])->assertStatus(201);
    $this->raw('/orders', $nested(512), ['Content-Type' => 'application/json'], 'POST')->assertStatus(400)->assertJson(['code' => 'idempotency_request_invalid']);
});

test('an invalid configuration fails loudly instead of running unprotected', function () {
    config(['pharaonic.api-idempotency.lease' => 0]);

    $this->withoutExceptionHandling();
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('[pharaonic.api-idempotency.lease] must be an integer from 1 to 86400.');

    try {
        $this->idempotent('/orders', ['quantity' => 2]);
    } finally {
        $this->assertNothingRan();
    }
});

test('a non atomic store driver fails loudly', function () {
    $this->withoutExceptionHandling();
    config(['pharaonic.api-idempotency.store.driver' => 'file']);

    try {
        $this->idempotent('/orders', ['quantity' => 2]);
        $this->fail('A store without atomic reservation must throw.');
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toBe('[pharaonic.api-idempotency.store.driver] must be "database" or "redis": both reserve keys atomically.');
    }

    $this->assertNothingRan();
});
