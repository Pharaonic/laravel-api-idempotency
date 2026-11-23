<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\Order;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class ReplayTest extends TestCase
{
    public function test_the_first_request_runs_the_route_once_and_a_retry_replays_it(): void
    {
        $first = $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 2]);
        $first->assertStatus(201);
        $first->assertExactJson(['id' => 1, 'status' => 'created', 'user' => 1, 'input' => ['product_id' => 15, 'quantity' => 2]]);
        $this->assertNull($first->headers->get('Idempotency-Replayed'));

        $second = $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 2]);
        $second->assertStatus(201);
        $this->assertSame($first->getContent(), $second->getContent());
        $this->assertSame('true', $second->headers->get('Idempotency-Replayed'));
        $this->assertSame('/orders/1', $second->headers->get('Location'));
        $this->assertSame('application/json', $second->headers->get('Content-Type'));
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_a_replay_never_carries_cookies_or_unlisted_headers(): void
    {
        $first = $this->idempotent('/orders', ['quantity' => 2]);
        $this->assertNotEmpty($first->headers->getCookies());
        $this->assertSame('private-1', $first->headers->get('X-Debug-Token'));

        $second = $this->idempotent('/orders', ['quantity' => 2]);
        $this->assertSame([], $second->headers->getCookies());
        $this->assertNull($second->headers->get('Set-Cookie'));
        $this->assertNull($second->headers->get('X-Debug-Token'));
    }

    public function test_json_object_key_order_does_not_matter(): void
    {
        $this->raw('/orders', '{"product_id":15,"quantity":2}', ['Content-Type' => 'application/json'])->assertStatus(201);
        $replay = $this->raw('/orders', '{ "quantity": 2, "product_id": 15 }', ['Content-Type' => 'application/json']);

        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_the_same_key_with_another_body_is_a_conflict(): void
    {
        $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 2])->assertStatus(201);

        $conflict = $this->idempotent('/orders', ['product_id' => 15, 'quantity' => 5]);

        $conflict->assertStatus(409);
        $conflict->assertExactJson(['message' => 'This Idempotency-Key was already used with a different request.', 'code' => 'idempotency_key_conflict']);
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_types_and_list_order_are_part_of_the_fingerprint(): void
    {
        $this->idempotent('/orders', ['quantity' => 2, 'tags' => ['a', 'b']])->assertStatus(201);

        $this->idempotent('/orders', ['quantity' => '2', 'tags' => ['a', 'b']])->assertStatus(409);
        $this->raw('/orders', '{"quantity":2.0,"tags":["a","b"]}', ['Content-Type' => 'application/json'])->assertStatus(409);
        $this->idempotent('/orders', ['quantity' => 2, 'tags' => ['b', 'a']])->assertStatus(409);
        $this->raw('/orders', '{"quantity":2,"tags":["a","b"],"extra":{}}', ['Content-Type' => 'application/json'])->assertStatus(409);
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_the_query_string_is_part_of_the_fingerprint(): void
    {
        $this->idempotent('/orders?coupon=A', ['quantity' => 2])->assertStatus(201);

        $this->idempotent('/orders?coupon=B', ['quantity' => 2])->assertStatus(409);
        $this->idempotent('/orders?coupon=A', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_route_parameters_are_part_of_the_fingerprint(): void
    {
        Order::query()->insert([['id' => 1, 'total' => 100], ['id' => 2, 'total' => 250]]);

        $this->idempotent('/orders/1/refunds')->assertStatus(201)->assertExactJson(['refunded' => 1, 'total' => 100]);

        $this->idempotent('/orders/2/refunds')->assertStatus(409)->assertJson(['code' => 'idempotency_key_conflict']);
        $this->idempotent('/orders/1/refunds')->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_other_routes_and_methods_have_their_own_keys(): void
    {
        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201)->assertJson(['id' => 1]);
        $this->idempotent('/orders/copy', ['quantity' => 2])->assertStatus(201)->assertJson(['id' => 2]);
        $this->idempotent('/orders', ['quantity' => 2], 'key-1', 1, [], 'PUT')->assertStatus(201)->assertJson(['id' => 3]);

        $this->assertSame(3, SideEffects::$count);
    }

    public function test_users_never_share_keys_or_responses(): void
    {
        $alice = $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 1);
        $bob = $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 2);

        $alice->assertStatus(201)->assertJson(['id' => 1, 'user' => 1]);
        $bob->assertStatus(201)->assertJson(['id' => 2, 'user' => 2]);
        $this->assertNull($bob->headers->get('Idempotency-Replayed'));

        $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 2)->assertJson(['id' => 2, 'user' => 2])->assertHeader('Idempotency-Replayed', 'true');
        $this->idempotent('/orders', ['quantity' => 2], 'shared-key', 1)->assertJson(['id' => 1, 'user' => 1])->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(2, SideEffects::$count);
    }

    public function test_sequential_requests_in_one_application_keep_their_own_context(): void
    {
        // Like an Octane worker: one application instance serves every request.
        foreach ([[1, 'a'], [2, 'a'], [1, 'b'], [2, 'b']] as [$user, $key]) {
            $this->idempotent('/orders', ['quantity' => 1], $key, $user)->assertStatus(201)->assertJson(['user' => $user]);
        }

        foreach ([[2, 'b', 4], [1, 'a', 1], [2, 'a', 2], [1, 'b', 3]] as [$user, $key, $id]) {
            $this->idempotent('/orders', ['quantity' => 1], $key, $user)->assertJson(['id' => $id, 'user' => $user])->assertHeader('Idempotency-Replayed', 'true');
        }

        $this->assertSame(4, SideEffects::$count);
    }

    public function test_an_empty_204_is_replayed(): void
    {
        $this->idempotent('/no-content')->assertStatus(204);
        $replay = $this->idempotent('/no-content');

        $replay->assertStatus(204);
        $this->assertSame('', $replay->getContent());
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_requests_without_a_body_are_fingerprinted_too(): void
    {
        $this->raw('/orders', '')->assertStatus(201);
        $this->raw('/orders', '')->assertHeader('Idempotency-Replayed', 'true');
        $this->raw('/orders', 'null', ['Content-Type' => 'application/json'])->assertStatus(409);
    }

    public function test_safe_methods_pass_through_without_a_key(): void
    {
        $this->getJson('/resources', ['Authorization' => 'Bearer user-1'])->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/resources/7', ['Authorization' => 'Bearer user-1'])->assertOk();

        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_the_route_ttl_reaches_the_middleware(): void
    {
        $this->idempotent('/orders/short', ['quantity' => 2])->assertStatus(201);

        $record = DB::table('idempotency_keys')->first();
        $this->assertNotNull($record);
        $this->assertSame(self::START + 600, (int) $record->expires_at);
    }

    public function test_the_middleware_alias_works_on_groups(): void
    {
        $this->idempotent('/aliased', ['quantity' => 2])->assertStatus(201);
        $this->idempotent('/aliased', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');

        $record = DB::table('idempotency_keys')->first();
        $this->assertNotNull($record);
        $this->assertSame(self::START + 600, (int) $record->expires_at);
    }

    public function test_api_resources_protect_their_unsafe_actions(): void
    {
        $this->idempotent('/resources', [])->assertStatus(201);
        $this->idempotent('/resources', [])->assertHeader('Idempotency-Replayed', 'true');
        $this->idempotent('/resources/5', ['name' => 'x'], 'key-2', 1, [], 'PATCH')->assertOk()->assertJson(['updates' => 2]);
        $this->idempotent('/resources/5', ['name' => 'x'], 'key-2', 1, [], 'PATCH')->assertHeader('Idempotency-Replayed', 'true');
        $this->idempotent('/resources/5', [], 'key-3', 1, [], 'DELETE')->assertOk()->assertJson(['deletes' => 3]);
        $this->idempotent('/resources/5', [], null, 1, [], 'DELETE')->assertStatus(400);

        $this->assertSame(3, SideEffects::$count);
    }

    public function test_a_custom_scope_resolver_can_scope_anonymous_clients(): void
    {
        config(['pharaonic.api-idempotency.scope' => \Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\TenantScope::class]);

        $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0, ['X-Tenant' => 'acme'])->assertStatus(201)->assertJson(['id' => 1]);
        $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0, ['X-Tenant' => 'globex'])->assertStatus(201)->assertJson(['id' => 2]);
        $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0, ['X-Tenant' => 'acme'])->assertJson(['id' => 1])->assertHeader('Idempotency-Replayed', 'true');
        $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0)->assertStatus(401)->assertJson(['code' => 'idempotency_unauthenticated']);
    }

    public function test_the_storage_never_holds_the_raw_key_identity_or_request_body(): void
    {
        $this->idempotent('/orders', ['card' => '4242424242424242'], 'customer-secret-key', 1);

        $record = (array) DB::table('idempotency_keys')->first();
        $stored = json_encode(array_diff_key($record, ['response_body' => true]));

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $record['storage_key']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $record['request_fingerprint']);
        $this->assertStringNotContainsString('customer-secret-key', (string) $stored);
        $this->assertStringNotContainsString('4242424242424242', (string) $stored);
        $this->assertStringNotContainsString('GenericUser', (string) $stored);
        $this->assertSame('/orders', $record['route']);
    }
}
