<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestUnresolved;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\MemoryLogger;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;
use Psr\Log\LoggerInterface;

test('a client error releases the key for a corrected retry', function () {
    $this->idempotent('/invalid', ['quantity' => 'x'])->assertStatus(422);
    expect($this->storedState())->toBeNull();

    $this->idempotent('/invalid', ['quantity' => 2])->assertStatus(422);
    expect(SideEffects::$count)->toBe(2);
});

test('any 2xx response with a json type is stored and replayed', function () {
    Route::post('vendor-json', static function (): Response {
        SideEffects::record('vendor');

        return new Response('{"ok":true}', 203, ['Content-Type' => 'application/vnd.api+json; charset=utf-8']);
    })->middleware('auth:fixture')->idempotent();

    $this->idempotent('/vendor-json')->assertStatus(203);
    $this->idempotent('/vendor-json')
        ->assertStatus(203)
        ->assertHeader('Content-Type', 'application/vnd.api+json; charset=utf-8')
        ->assertHeader('Idempotency-Replayed', 'true');

    expect(SideEffects::$count)->toBe(1);
});

test('a server error locks the key as an unknown outcome', function () {
    $this->idempotent('/fail', ['amount' => 10])->assertStatus(500);
    expect($this->storedState())->toBe('unknown');

    $this->idempotent('/fail', ['amount' => 10])
        ->assertStatus(409)
        ->assertExactJson(['message' => 'The outcome of the original request with this idempotency key is unknown, so it will not run again.', 'code' => 'idempotency_outcome_unknown']);
    $this->idempotent('/fail', ['amount' => 99])->assertStatus(409)->assertJson(['code' => 'idempotency_key_conflict']);
    expect(SideEffects::$count)->toBe(1);
});

test('an unknown outcome expires after the ttl from when it was recorded', function () {
    $this->idempotent('/fail', ['amount' => 10])->assertStatus(500);

    $this->advance(86399);
    $this->idempotent('/fail', ['amount' => 10])->assertStatus(409);

    $this->advance(1);
    $this->idempotent('/fail', ['amount' => 10])->assertStatus(500);
    expect(SideEffects::$count)->toBe(2);
});

dataset('unstorableResponses', function () {
    return [
        'html' => ['/html'],
        'streamed' => ['/stream'],
        'too large' => ['/large'],
        'redirect' => ['/redirect'],
    ];
});

test('a success that cannot be replayed is returned once and then locked', function (string $uri) {
    config(['pharaonic.api-idempotency.max_response_bytes' => 1024]);

    $first = $this->idempotent($uri);
    expect($first->getStatusCode())->toBeLessThan(400);
    expect($this->storedState())->toBe('unknown');

    $this->idempotent($uri)->assertStatus(409)->assertJson(['code' => 'idempotency_outcome_unknown']);
    expect(SideEffects::$count)->toBe(1);
})->with('unstorableResponses');

test('a response at the size limit is stored', function () {
    $size = strlen((string) $this->idempotent('/large', [], 'probe')->getContent());
    DB::table('idempotency_keys')->delete();
    config(['pharaonic.api-idempotency.max_response_bytes' => $size]);

    $this->idempotent('/large')->assertOk();
    expect($this->storedState())->toBe('completed');
});

test('a duplicate while the first request runs is told to retry later', function () {
    $duplicate = null;
    $conflict = null;

    SideEffects::$during = function () use (&$duplicate, &$conflict): void {
        SideEffects::$during = null;
        $this->advance(20);
        $duplicate = $this->nested('/orders', '{"quantity":2}');
        $conflict = $this->nested('/orders', '{"quantity":3}');
    };

    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201);

    expect($duplicate)->not->toBeNull();
    expect($duplicate->getStatusCode())->toBe(409);
    expect($duplicate->headers->get('Retry-After'))->toBe('100');
    expect(json_decode((string) $duplicate->getContent(), true))->toBe(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_in_progress']);
    expect($conflict)->not->toBeNull();
    expect($conflict->getStatusCode())->toBe(409);
    expect((string) $conflict->getContent())->toContain('idempotency_key_conflict');
    expect(SideEffects::$count)->toBe(1);

    $this->idempotent('/orders', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');
});

test('a request abandoned after its reservation is never run again by default', function () {
    $this->abandonReservation();

    $this->advance(119);
    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(409)->assertHeader('Retry-After', '1')->assertJson(['code' => 'idempotency_in_progress']);

    // The lease is not the TTL: an expired lease means "unknown", never "retry".
    $this->advance(1);
    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(409)->assertJson(['code' => 'idempotency_outcome_unknown']);
    $this->advance(80000);
    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(409)->assertJson(['code' => 'idempotency_outcome_unknown']);
    expect(SideEffects::$count)->toBe(0);

    // The reservation itself expires with the TTL; then the key is new.
    $this->advance(86400 - 80000 - 120);
    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201);
    expect(SideEffects::$count)->toBe(1);
});

test('the completed ttl counts from completion and ends at the boundary', function () {
    $this->idempotent('/orders/short', ['quantity' => 2])->assertStatus(201);

    $this->advance(599);
    $this->idempotent('/orders/short', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');

    $this->advance(1);
    $retry = $this->idempotent('/orders/short', ['quantity' => 2]);
    $retry->assertStatus(201)->assertJson(['id' => 2]);
    expect($retry->headers->get('Idempotency-Replayed'))->toBeNull();
});

test('the default ttl is 24 hours', function () {
    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201);

    $this->advance(86399);
    $this->idempotent('/orders', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');

    $this->advance(1);
    $this->idempotent('/orders', ['quantity' => 2])->assertJson(['id' => 2]);
});

test('the ttl starts when the request finishes not when it starts', function () {
    SideEffects::$during = function (): void {
        SideEffects::$during = null;
        $this->advance(100);
    };

    $this->idempotent('/orders/short', ['quantity' => 2])->assertStatus(201);

    $this->advance(599);
    $this->idempotent('/orders/short', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');
});

test('a store that cannot reserve fails closed', function () {
    config(['pharaonic.api-idempotency.store.table' => 'missing_table']);

    $this->idempotent('/orders', ['quantity' => 2])
        ->assertStatus(503)
        ->assertExactJson(['message' => 'The idempotency store is unavailable, so the request was not processed.', 'code' => 'idempotency_store_unavailable']);

    expect(SideEffects::$count)->toBe(0);
});

test('a store that fails after the route ran still returns its response', function () {
    Event::fake([IdempotentRequestUnresolved::class]);

    SideEffects::$during = static function (): void {
        Schema::drop('idempotency_keys');
    };

    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201)->assertJson(['id' => 1]);

    Event::assertDispatched(IdempotentRequestUnresolved::class, static fn (IdempotentRequestUnresolved $event): bool => $event->reason === 'store_failed');
});

test('store failures never log the sql bindings and so never the response', function () {
    $logger = new MemoryLogger;
    $this->app->instance(LoggerInterface::class, $logger);

    SideEffects::$during = static function (): void {
        Schema::drop('idempotency_keys');
    };

    $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201)->assertJson(['id' => 1]);

    expect($logger->records)->not->toBeEmpty();

    foreach ($logger->records as $record) {
        expect($record['context'])->not->toContain('created');
        expect($record['context'])->not->toContain('response_body');
        expect($record['context'])->not->toContain('owner_token');
    }

    expect($logger->records[0]['context'])->toContain('QueryException');
});
