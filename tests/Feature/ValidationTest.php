<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Feature;

use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class ValidationTest extends TestCase
{
    public function test_a_missing_key_is_rejected_before_the_route_runs(): void
    {
        $this->idempotent('/orders', ['quantity' => 2], null)
            ->assertStatus(400)
            ->assertExactJson(['message' => 'The Idempotency-Key header is required.', 'code' => 'idempotency_key_missing']);

        $this->idempotent('/orders', ['quantity' => 2], '')->assertStatus(400)->assertJson(['code' => 'idempotency_key_missing']);
        $this->assertNothingRan();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'too long' => [str_repeat('k', 256)],
            'space' => ['order 123'],
            'non-ascii' => ['طلب-123'],
            'control character' => ["order\t123"],
        ];
    }

    /**
     * @dataProvider invalidKeys
     */
    public function test_a_malformed_key_is_rejected_before_the_route_runs(string $key): void
    {
        $this->idempotent('/orders', ['quantity' => 2], $key)
            ->assertStatus(400)
            ->assertExactJson(['message' => 'The Idempotency-Key header must be one value of 1 to 255 visible ASCII characters.', 'code' => 'idempotency_key_invalid']);

        $this->assertNothingRan();
    }

    public function test_keys_of_1_and_255_visible_characters_are_accepted(): void
    {
        $this->idempotent('/orders', ['quantity' => 2], '7')->assertStatus(201);
        $this->idempotent('/orders', ['quantity' => 2], str_repeat('~', 255))->assertStatus(201);
        $this->idempotent('/orders', ['quantity' => 2], '"quoted-uuid"')->assertStatus(201);
    }

    public function test_a_repeated_key_header_is_rejected(): void
    {
        $server = $this->transformHeadersToServerVars(['Authorization' => 'Bearer user-1', 'Content-Type' => 'application/json']);
        $request = \Illuminate\Http\Request::create('/orders', 'POST', [], [], [], $server, '{}');
        $request->headers->set('Idempotency-Key', ['a', 'b']);

        $response = $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('idempotency_key_invalid', (string) $response->getContent());
        $this->assertNothingRan();
    }

    public function test_an_unauthenticated_request_is_rejected_instead_of_sharing_an_anonymous_key_space(): void
    {
        $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0)
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Idempotent requests must be authenticated.', 'code' => 'idempotency_unauthenticated']);

        $this->assertNothingRan();
    }

    public function test_authentication_runs_first_even_when_it_is_added_after_the_macro(): void
    {
        // auth:fixture is declared after ->idempotent(); the middleware priority still runs it first.
        $this->idempotent('/late-auth', ['quantity' => 2], 'key-1', 0)->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
        $this->idempotent('/late-auth', ['quantity' => 2], 'key-1', 2)->assertStatus(201)->assertJson(['user' => 2]);
        $this->idempotent('/late-auth', ['quantity' => 2], 'key-1', 2)->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_a_non_json_body_is_unsupported(): void
    {
        $this->raw('/orders', 'quantity=2', ['Content-Type' => 'application/x-www-form-urlencoded'])
            ->assertStatus(415)
            ->assertExactJson(['message' => 'Idempotent requests must have a JSON body or none.', 'code' => 'idempotency_request_unsupported']);

        $this->raw('/orders', '{"quantity":2}', ['Content-Type' => 'text/plain'])->assertStatus(415);
        $this->assertNothingRan();
    }

    public function test_structured_json_media_types_are_accepted(): void
    {
        $this->raw('/orders', '{"quantity":2}', ['Content-Type' => 'application/merge-patch+json'])->assertStatus(201);
    }

    public function test_invalid_json_is_rejected(): void
    {
        $this->raw('/orders', '{"quantity":', ['Content-Type' => 'application/json'])
            ->assertStatus(400)
            ->assertExactJson(['message' => 'The request body is not valid JSON or is nested too deeply.', 'code' => 'idempotency_request_invalid']);

        $this->assertNothingRan();
    }

    public function test_json_nested_deeper_than_json_decode_allows_is_rejected(): void
    {
        $nested = static fn (int $depth): string => str_repeat('[', $depth).str_repeat(']', $depth);

        // json_decode() at its default depth of 512 accepts 511 levels.
        $this->raw('/orders', $nested(511), ['Content-Type' => 'application/json'])->assertStatus(201);
        $this->raw('/orders', $nested(512), ['Content-Type' => 'application/json'], 'POST')->assertStatus(400)->assertJson(['code' => 'idempotency_request_invalid']);
    }

    public function test_an_invalid_configuration_fails_loudly_instead_of_running_unprotected(): void
    {
        config(['pharaonic.api-idempotency.lease' => 0]);

        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[pharaonic.api-idempotency.lease] must be an integer from 1 to 86400.');

        try {
            $this->idempotent('/orders', ['quantity' => 2]);
        } finally {
            $this->assertNothingRan();
        }
    }

    public function test_a_non_atomic_store_driver_fails_loudly(): void
    {
        $this->withoutExceptionHandling();
        config(['pharaonic.api-idempotency.store.driver' => 'file']);

        try {
            $this->idempotent('/orders', ['quantity' => 2]);
            $this->fail('A store without atomic reservation must throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('[pharaonic.api-idempotency.store.driver] must be "database" or "redis": both reserve keys atomically.', $e->getMessage());
        }

        $this->assertNothingRan();
    }
}
