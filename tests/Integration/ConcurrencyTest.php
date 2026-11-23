<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Integration;

use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

/**
 * Real concurrency: separate PHP processes with their own connections,
 * released at the same instant against a shared backend.
 */
final class ConcurrencyTest extends TestCase
{
    /**
     * @dataProvider backends
     */
    public function test_simultaneous_identical_requests_run_the_route_once(string $backend): void
    {
        $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'), 'IDEMPOTENCY_TEST_SLEEP_MS' => '400'];

        $results = self::spawn(array_fill(0, 8, self::job('/orders', ['product_id' => 15, 'quantity' => 2])), $env);

        $this->assertSame(['store'], SideEffects::ledger($ledger));
        $this->assertSame(1, count(array_filter($results, static fn (array $r): bool => ($r['status'] ?? null) === 201 && ! isset($r['headers']['idempotency-replayed']))), $this->describeResults($results));

        foreach ($results as $result) {
            $this->assertArrayNotHasKey('crashed', $result, $this->describeResults($results));

            if ($result['status'] === 409) {
                $this->assertSame('idempotency_in_progress', json_decode($result['body'], true)['code']);
                $this->assertNotEmpty($result['headers']['retry-after'] ?? null);
            } else {
                $this->assertSame(201, $result['status']);
            }
        }

        // Once finished, every retry replays the one stored response.
        $replays = self::spawn(array_fill(0, 4, self::job('/orders', ['product_id' => 15, 'quantity' => 2])), $env);

        foreach ($replays as $replay) {
            $this->assertSame(201, $replay['status'] ?? null, $this->describeResults($replays));
            $this->assertSame(['true'], $replay['headers']['idempotency-replayed']);
            $this->assertSame(1, json_decode($replay['body'], true)['id']);
        }

        $this->assertSame(['store'], SideEffects::ledger($ledger));
    }

    /**
     * @dataProvider backends
     */
    public function test_a_burst_without_delay_still_reserves_once(string $backend): void
    {
        $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log')];

        $results = self::spawn(array_fill(0, 12, self::job('/orders', ['quantity' => 1], 'burst')), $env);

        $this->assertSame(['store'], SideEffects::ledger($ledger), $this->describeResults($results));

        foreach ($results as $result) {
            $this->assertContains($result['status'] ?? null, [201, 409], $this->describeResults($results));
        }
    }

    /**
     * @dataProvider backends
     */
    public function test_simultaneous_requests_with_different_keys_all_run(string $backend): void
    {
        $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'), 'IDEMPOTENCY_TEST_SLEEP_MS' => '200'];
        $jobs = [];

        for ($i = 0; $i < 6; $i++) {
            $jobs[] = self::job('/orders', ['quantity' => 1], 'key-'.$i);
        }

        $results = self::spawn($jobs, $env);

        $this->assertCount(6, SideEffects::ledger($ledger), $this->describeResults($results));

        foreach ($results as $result) {
            $this->assertSame(201, $result['status'] ?? null, $this->describeResults($results));
            $this->assertArrayNotHasKey('idempotency-replayed', $result['headers']);
        }
    }

    /**
     * @dataProvider backends
     */
    public function test_simultaneous_reuse_of_a_key_with_different_bodies_runs_only_one(string $backend): void
    {
        $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'), 'IDEMPOTENCY_TEST_SLEEP_MS' => '300'];
        $jobs = [];

        for ($i = 1; $i <= 6; $i++) {
            $jobs[] = self::job('/orders', ['quantity' => $i], 'reused');
        }

        $results = self::spawn($jobs, $env);
        $statuses = array_map(static fn (array $r) => $r['status'] ?? null, $results);
        sort($statuses);

        $this->assertSame(['store'], SideEffects::ledger($ledger), $this->describeResults($results));
        $this->assertSame([201, 409, 409, 409, 409, 409], $statuses, $this->describeResults($results));
    }

    /**
     * @dataProvider backends
     */
    public function test_the_same_key_for_different_users_runs_for_each(string $backend): void
    {
        $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'), 'IDEMPOTENCY_TEST_SLEEP_MS' => '200'];
        $jobs = [];

        for ($user = 1; $user <= 4; $user++) {
            $jobs[] = self::job('/orders', ['quantity' => 1], 'shared', $user);
        }

        $results = self::spawn($jobs, $env);

        $this->assertCount(4, SideEffects::ledger($ledger), $this->describeResults($results));

        foreach ($results as $index => $result) {
            $this->assertSame(201, $result['status'] ?? null);
            $this->assertSame($index + 1, json_decode($result['body'], true)['user']);
        }
    }
}
