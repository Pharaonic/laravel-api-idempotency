<?php

declare(strict_types=1);

use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;

/**
 * Real concurrency: separate PHP processes with their own connections,
 * released at the same instant against a shared backend.
 */
test('simultaneous identical requests run the route once', function (string $backend) {
    $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'), 'IDEMPOTENCY_TEST_SLEEP_MS' => '400'];

    $results = self::spawn(array_fill(0, 8, self::job('/orders', ['product_id' => 15, 'quantity' => 2])), $env);

    expect(SideEffects::ledger($ledger))->toBe(['store']);
    $this->assertSame(1, count(array_filter($results, static fn (array $r): bool => ($r['status'] ?? null) === 201 && ! isset($r['headers']['idempotency-replayed']))), $this->describeResults($results));

    foreach ($results as $result) {
        $this->assertArrayNotHasKey('crashed', $result, $this->describeResults($results));

        if ($result['status'] === 409) {
            expect(json_decode($result['body'], true)['code'])->toBe('idempotency_in_progress');
            expect($result['headers']['retry-after'] ?? null)->not->toBeEmpty();
        } else {
            expect($result['status'])->toBe(201);
        }
    }

    // Once finished, every retry replays the one stored response.
    $replays = self::spawn(array_fill(0, 4, self::job('/orders', ['product_id' => 15, 'quantity' => 2])), $env);

    foreach ($replays as $replay) {
        $this->assertSame(201, $replay['status'] ?? null, $this->describeResults($replays));
        expect($replay['headers']['idempotency-replayed'])->toBe(['true']);
        expect(json_decode($replay['body'], true)['id'])->toBe(1);
    }

    expect(SideEffects::ledger($ledger))->toBe(['store']);
})->with('backends');

test('a burst without delay still reserves once', function (string $backend) {
    $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log')];

    $results = self::spawn(array_fill(0, 12, self::job('/orders', ['quantity' => 1], 'burst')), $env);

    $this->assertSame(['store'], SideEffects::ledger($ledger), $this->describeResults($results));

    foreach ($results as $result) {
        $this->assertContains($result['status'] ?? null, [201, 409], $this->describeResults($results));
    }
})->with('backends');

test('simultaneous requests with different keys all run', function (string $backend) {
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
})->with('backends');

test('simultaneous reuse of a key with different bodies runs only one', function (string $backend) {
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
})->with('backends');

test('the same key for different users runs for each', function (string $backend) {
    $env = $this->useBackend($backend) + ['IDEMPOTENCY_TEST_LEDGER' => $ledger = $this->temporaryFile('log'), 'IDEMPOTENCY_TEST_SLEEP_MS' => '200'];
    $jobs = [];

    for ($user = 1; $user <= 4; $user++) {
        $jobs[] = self::job('/orders', ['quantity' => 1], 'shared', $user);
    }

    $results = self::spawn($jobs, $env);

    $this->assertCount(4, SideEffects::ledger($ledger), $this->describeResults($results));

    foreach ($results as $index => $result) {
        expect($result['status'] ?? null)->toBe(201);
        expect(json_decode($result['body'], true)['user'])->toBe($index + 1);
    }
})->with('backends');
