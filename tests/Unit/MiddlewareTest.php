<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;
use Pharaonic\Laravel\ApiIdempotency\Http\Middleware\EnsureIdempotency;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;
use RuntimeException;

final class MiddlewareTest extends TestCase
{
    public function test_an_exception_that_escapes_the_route_locks_the_key_and_is_rethrown(): void
    {
        $middleware = $this->app->make(EnsureIdempotency::class);
        $calls = 0;

        try {
            $middleware->handle($this->middlewareRequest(), static function () use (&$calls): void {
                $calls++;

                throw new RuntimeException('Connection lost after the charge.');
            });
            $this->fail('The exception must be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Connection lost after the charge.', $e->getMessage());
        }

        $this->assertSame('unknown', $this->storedState());

        try {
            $middleware->handle($this->middlewareRequest(), static function () use (&$calls): JsonResponse {
                $calls++;

                return new JsonResponse([], 201);
            });
            $this->fail('A locked key must be refused.');
        } catch (IdempotencyException $e) {
            $this->assertSame(IdempotencyException::OUTCOME_UNKNOWN, $e->errorCode());
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertSame(1, $calls);
    }

    public function test_a_request_outside_a_route_is_scoped_by_its_host_and_path(): void
    {
        $middleware = $this->app->make(EnsureIdempotency::class);

        $middleware->handle($this->middlewareRequest(), static fn (): JsonResponse => new JsonResponse(['ok' => true], 201));

        $this->assertSame('localhost/orders', DB::table('idempotency_keys')->value('route'));
    }

    public function test_the_exception_renders_the_documented_json(): void
    {
        $response = IdempotencyException::inProgress(0)->render();

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('1', $response->headers->get('Retry-After'));
        $this->assertSame(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_in_progress'], $response->getData(true));
    }
}
