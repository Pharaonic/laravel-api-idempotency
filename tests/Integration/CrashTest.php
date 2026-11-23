<?php

declare(strict_types=1);

use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;

/**
 * A worker killed (SIGKILL) after the route's side effect and before the
 * outcome was stored: the package cannot know whether it happened.
 */
test('a killed worker never leads to a second run', function (string $backend) {
    $env = $this->crashWorker($backend, $ledger);

    $inProgress = self::spawn([self::job('/orders', ['quantity' => 2], 'key-1', 1, ['IDEMPOTENCY_TEST_NOW' => (string) (self::START + 60)])], $env)[0];
    $this->assertSame(409, $inProgress['status'] ?? null, (string) json_encode($inProgress));
    expect(json_decode($inProgress['body'], true)['code'])->toBe('idempotency_in_progress');
    expect($inProgress['headers']['retry-after'])->toBe(['60']);

    $unknown = self::spawn([self::job('/orders', ['quantity' => 2], 'key-1', 1, ['IDEMPOTENCY_TEST_NOW' => (string) (self::START + 121)])], $env)[0];
    expect($unknown['status'] ?? null)->toBe(409);
    expect(json_decode($unknown['body'], true)['code'])->toBe('idempotency_outcome_unknown');

    expect(SideEffects::ledger($ledger))->toBe(['store']);
})->with('backends');
