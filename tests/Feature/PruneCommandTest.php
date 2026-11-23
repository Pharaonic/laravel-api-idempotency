<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class PruneCommandTest extends TestCase
{
    public function test_it_only_counts_without_apply(): void
    {
        $this->seedPruneRecords();
        $this->advance(700);

        $this->artisan('idempotency:prune')
            ->expectsOutput('1 expired completed records would be deleted. Run with --apply to delete them.')
            ->assertExitCode(0);

        $this->assertSame(4, DB::table('idempotency_keys')->count());
    }

    public function test_it_deletes_expired_completed_records_and_keeps_unresolved_ones(): void
    {
        $this->seedPruneRecords();
        $this->advance(700);

        $this->artisan('idempotency:prune', ['--apply' => true])
            ->expectsOutput('Deleted 1 expired completed records.')
            ->expectsOutput('2 unresolved records (still processing, or with an unknown outcome) are kept. Reconcile them, then remove them with --unresolved once they expire.')
            ->assertExitCode(0);

        $this->assertSame(['completed', 'processing', 'unknown'], DB::table('idempotency_keys')->orderBy('state')->pluck('state')->all());

        // Running it again changes nothing.
        $this->artisan('idempotency:prune', ['--apply' => true])->expectsOutput('Deleted 0 expired completed records.')->assertExitCode(0);
        $this->assertSame(3, DB::table('idempotency_keys')->count());
    }

    public function test_unresolved_records_are_deleted_only_when_asked_and_expired(): void
    {
        $this->seedPruneRecords();
        $this->advance(86400 + 700);

        $this->artisan('idempotency:prune', ['--apply' => true, '--unresolved' => true])
            ->expectsOutput('Deleted 4 expired records.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_the_redis_store_needs_no_pruning(): void
    {
        config(['pharaonic.api-idempotency.store.driver' => 'redis']);

        $this->artisan('idempotency:prune', ['--apply' => true])
            ->expectsOutput('The redis store expires its records by itself; nothing to prune.')
            ->assertExitCode(0);
    }

    public function test_the_published_migration_can_be_rolled_back_and_run_again(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertTrue(Schema::hasTable('idempotency_keys'));
        $kernel->call('migrate:rollback', self::MIGRATIONS);
        $this->assertFalse(Schema::hasTable('idempotency_keys'));
        $kernel->call('migrate', self::MIGRATIONS);
        $kernel->call('migrate', self::MIGRATIONS);
        $this->assertTrue(Schema::hasTable('idempotency_keys'));
        $this->assertTrue(Schema::hasColumns('idempotency_keys', ['storage_key', 'request_fingerprint', 'state', 'owner_token', 'created_at', 'lease_expires_at', 'expires_at', 'completed_at', 'response_status', 'response_headers', 'response_body']));
    }
}
