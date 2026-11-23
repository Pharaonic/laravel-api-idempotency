<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Integration;

use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\SideEffects;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

/**
 * A worker killed (SIGKILL) after the route's side effect and before the
 * outcome was stored: the package cannot know whether it happened.
 */
final class CrashTest extends TestCase
{
    /**
     * @dataProvider backends
     */
    public function test_a_killed_worker_never_leads_to_a_second_run(string $backend): void
    {
        $env = $this->crashWorker($backend, $ledger);

        $inProgress = self::spawn([self::job('/orders', ['quantity' => 2], 'key-1', 1, ['IDEMPOTENCY_TEST_NOW' => (string) (self::START + 60)])], $env)[0];
        $this->assertSame(409, $inProgress['status'] ?? null, (string) json_encode($inProgress));
        $this->assertSame('idempotency_in_progress', json_decode($inProgress['body'], true)['code']);
        $this->assertSame(['60'], $inProgress['headers']['retry-after']);

        $unknown = self::spawn([self::job('/orders', ['quantity' => 2], 'key-1', 1, ['IDEMPOTENCY_TEST_NOW' => (string) (self::START + 121)])], $env)[0];
        $this->assertSame(409, $unknown['status'] ?? null);
        $this->assertSame('idempotency_outcome_unknown', json_decode($unknown['body'], true)['code']);

        $this->assertSame(['store'], SideEffects::ledger($ledger));
    }
}
