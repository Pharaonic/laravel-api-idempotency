<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Feature;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestUnresolved;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class OutcomeTest extends TestCase
{
    public function test_a_client_error_releases_the_key_for_a_corrected_retry(): void
    {
        $this->idempotent('/invalid', ['quantity' => 'x'])->assertStatus(422);
        $this->assertNull($this->storedState());

        $this->idempotent('/invalid', ['quantity' => 2])->assertStatus(422);
        $this->assertSame(2, SideEffects::$count);
    }

    public function test_any_2xx_response_with_a_json_type_is_stored_and_replayed(): void
    {
        Route::post('vendor-json', static function (): Response {
            SideEffects::record('vendor');

            return new Response('{"ok":true}', 203, ['Content-Type' => 'application/vnd.api+json; charset=utf-8']);
        })->middleware('auth:fixture')->idempotent();

        $this->idempotent('/vendor-json')->assertStatus(203);
        $this->idempotent('/vendor-json')
            ->assertStatus(203)
            ->assertHeader('Content-Type', 'application/vnd.api+json; charset=utf-8')
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, SideEffects::$count);
    }

    public function test_a_server_error_locks_the_key_as_an_unknown_outcome(): void
    {
        $this->idempotent('/fail', ['amount' => 10])->assertStatus(500);
        $this->assertSame('unknown', $this->storedState());

        $this->idempotent('/fail', ['amount' => 10])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'The outcome of the original request with this idempotency key is unknown, so it will not run again.', 'code' => 'idempotency_outcome_unknown']);
        $this->idempotent('/fail', ['amount' => 99])->assertStatus(409)->assertJson(['code' => 'idempotency_key_conflict']);
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_an_unknown_outcome_expires_after_the_ttl_from_when_it_was_recorded(): void
    {
        $this->idempotent('/fail', ['amount' => 10])->assertStatus(500);

        $this->advance(86399);
        $this->idempotent('/fail', ['amount' => 10])->assertStatus(409);

        $this->advance(1);
        $this->idempotent('/fail', ['amount' => 10])->assertStatus(500);
        $this->assertSame(2, SideEffects::$count);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unstorableResponses(): array
    {
        return [
            'html' => ['/html'],
            'streamed' => ['/stream'],
            'too large' => ['/large'],
            'redirect' => ['/redirect'],
        ];
    }

    /**
     * @dataProvider unstorableResponses
     */
    public function test_a_success_that_cannot_be_replayed_is_returned_once_and_then_locked(string $uri): void
    {
        config(['pharaonic.api-idempotency.max_response_bytes' => 1024]);

        $first = $this->idempotent($uri);
        $this->assertLessThan(400, $first->getStatusCode());
        $this->assertSame('unknown', $this->storedState());

        $this->idempotent($uri)->assertStatus(409)->assertJson(['code' => 'idempotency_outcome_unknown']);
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_a_response_at_the_size_limit_is_stored(): void
    {
        $size = strlen((string) $this->idempotent('/large', [], 'probe')->getContent());
        DB::table('idempotency_keys')->delete();
        config(['pharaonic.api-idempotency.max_response_bytes' => $size]);

        $this->idempotent('/large')->assertOk();
        $this->assertSame('completed', $this->storedState());
    }

    public function test_a_duplicate_while_the_first_request_runs_is_told_to_retry_later(): void
    {
        $duplicate = null;
        $conflict = null;

        SideEffects::$during = function () use (&$duplicate, &$conflict): void {
            SideEffects::$during = null;
            $this->advance(20);
            $duplicate = $this->nested('/orders', '{"quantity":2}');
            $conflict = $this->nested('/orders', '{"quantity":3}');
        };

        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201);

        $this->assertNotNull($duplicate);
        $this->assertSame(409, $duplicate->getStatusCode());
        $this->assertSame('100', $duplicate->headers->get('Retry-After'));
        $this->assertSame(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_in_progress'], json_decode((string) $duplicate->getContent(), true));
        $this->assertNotNull($conflict);
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertStringContainsString('idempotency_key_conflict', (string) $conflict->getContent());
        $this->assertSame(1, SideEffects::$count);

        $this->idempotent('/orders', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_a_request_abandoned_after_its_reservation_is_never_run_again_by_default(): void
    {
        $this->abandonReservation();

        $this->advance(119);
        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(409)->assertHeader('Retry-After', '1')->assertJson(['code' => 'idempotency_in_progress']);

        // The lease is not the TTL: an expired lease means "unknown", never "retry".
        $this->advance(1);
        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(409)->assertJson(['code' => 'idempotency_outcome_unknown']);
        $this->advance(80000);
        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(409)->assertJson(['code' => 'idempotency_outcome_unknown']);
        $this->assertSame(0, SideEffects::$count);

        // The reservation itself expires with the TTL; then the key is new.
        $this->advance(86400 - 80000 - 120);
        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201);
        $this->assertSame(1, SideEffects::$count);
    }

    public function test_the_completed_ttl_counts_from_completion_and_ends_at_the_boundary(): void
    {
        $this->idempotent('/orders/short', ['quantity' => 2])->assertStatus(201);

        $this->advance(599);
        $this->idempotent('/orders/short', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');

        $this->advance(1);
        $retry = $this->idempotent('/orders/short', ['quantity' => 2]);
        $retry->assertStatus(201)->assertJson(['id' => 2]);
        $this->assertNull($retry->headers->get('Idempotency-Replayed'));
    }

    public function test_the_default_ttl_is_24_hours(): void
    {
        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201);

        $this->advance(86399);
        $this->idempotent('/orders', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');

        $this->advance(1);
        $this->idempotent('/orders', ['quantity' => 2])->assertJson(['id' => 2]);
    }

    public function test_the_ttl_starts_when_the_request_finishes_not_when_it_starts(): void
    {
        SideEffects::$during = function (): void {
            SideEffects::$during = null;
            $this->advance(100);
        };

        $this->idempotent('/orders/short', ['quantity' => 2])->assertStatus(201);

        $this->advance(599);
        $this->idempotent('/orders/short', ['quantity' => 2])->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_a_store_that_cannot_reserve_fails_closed(): void
    {
        config(['pharaonic.api-idempotency.store.table' => 'missing_table']);

        $this->idempotent('/orders', ['quantity' => 2])
            ->assertStatus(503)
            ->assertExactJson(['message' => 'The idempotency store is unavailable, so the request was not processed.', 'code' => 'idempotency_store_unavailable']);

        $this->assertSame(0, SideEffects::$count);
    }

    public function test_a_store_that_fails_after_the_route_ran_still_returns_its_response(): void
    {
        Event::fake([IdempotentRequestUnresolved::class]);

        SideEffects::$during = static function (): void {
            Schema::drop('idempotency_keys');
        };

        $this->idempotent('/orders', ['quantity' => 2])->assertStatus(201)->assertJson(['id' => 1]);

        Event::assertDispatched(IdempotentRequestUnresolved::class, static fn (IdempotentRequestUnresolved $event): bool => $event->reason === 'store_failed');
    }
}
