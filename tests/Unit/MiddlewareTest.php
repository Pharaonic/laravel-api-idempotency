<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;

test('an exception that escapes the route locks the key and is rethrown', function () {
    $middleware = $this->app->make(EnsureIdempotency::class);
    $calls = 0;

    try {
        $middleware->handle($this->middlewareRequest(), static function () use (&$calls): void {
            $calls++;

            throw new RuntimeException('Connection lost after the charge.');
        });
        $this->fail('The exception must be rethrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Connection lost after the charge.');
    }

    expect($this->storedState())->toBe('unknown');

    try {
        $middleware->handle($this->middlewareRequest(), static function () use (&$calls): JsonResponse {
            $calls++;

            return new JsonResponse([], 201);
        });
        $this->fail('A locked key must be refused.');
    } catch (IdempotencyException $e) {
        expect($e->errorCode())->toBe(IdempotencyException::OUTCOME_UNKNOWN);
        expect($e->getStatusCode())->toBe(409);
    }

    expect($calls)->toBe(1);
});

test('a request outside a route is scoped by its host and path', function () {
    $middleware = $this->app->make(EnsureIdempotency::class);

    $middleware->handle($this->middlewareRequest(), static fn (): JsonResponse => new JsonResponse(['ok' => true], 201));

    expect(DB::table('idempotency_keys')->value('route'))->toBe('localhost/orders');
});

test('the exception renders the documented json', function () {
    $response = IdempotencyException::inProgress(0)->render();

    expect($response->getStatusCode())->toBe(409);
    expect($response->headers->get('Retry-After'))->toBe('1');
    expect($response->getData(true))->toBe(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_in_progress']);
});
