<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('it only counts without apply', function () {
    $this->seedPruneRecords();
    $this->advance(700);

    $this->artisan('idempotency:prune')
        ->expectsOutput('1 expired completed records would be deleted. Run with --apply to delete them.')
        ->assertExitCode(0);

    expect(DB::table('idempotency_keys')->count())->toBe(4);
});

test('it deletes expired completed records and keeps unresolved ones', function () {
    $this->seedPruneRecords();
    $this->advance(700);

    $this->artisan('idempotency:prune', ['--apply' => true])
        ->expectsOutput('Deleted 1 expired completed records.')
        ->expectsOutput('2 unresolved records (still processing, or with an unknown outcome) are kept. Reconcile them, then remove them with --unresolved once they expire.')
        ->assertExitCode(0);

    expect(DB::table('idempotency_keys')->orderBy('state')->pluck('state')->all())->toBe(['completed', 'processing', 'unknown']);

    // Running it again changes nothing.
    $this->artisan('idempotency:prune', ['--apply' => true])->expectsOutput('Deleted 0 expired completed records.')->assertExitCode(0);
    expect(DB::table('idempotency_keys')->count())->toBe(3);
});

test('unresolved records are deleted only when asked and expired', function () {
    $this->seedPruneRecords();
    $this->advance(86400 + 700);

    $this->artisan('idempotency:prune', ['--apply' => true, '--unresolved' => true])
        ->expectsOutput('Deleted 4 expired records.')
        ->assertExitCode(0);

    expect(DB::table('idempotency_keys')->count())->toBe(0);
});

test('the redis store needs no pruning', function () {
    config(['pharaonic.api-idempotency.store.driver' => 'redis']);

    $this->artisan('idempotency:prune', ['--apply' => true])
        ->expectsOutput('The redis store expires its records by itself; nothing to prune.')
        ->assertExitCode(0);
});

test('the published migration can be rolled back and run again', function () {
    $kernel = $this->app->make(Kernel::class);

    expect(Schema::hasTable('idempotency_keys'))->toBeTrue();
    $kernel->call('migrate:rollback', self::MIGRATIONS);
    expect(Schema::hasTable('idempotency_keys'))->toBeFalse();
    $kernel->call('migrate', self::MIGRATIONS);
    $kernel->call('migrate', self::MIGRATIONS);
    expect(Schema::hasTable('idempotency_keys'))->toBeTrue();
    expect(Schema::hasColumns('idempotency_keys', ['storage_key', 'request_fingerprint', 'state', 'owner_token', 'created_at', 'lease_expires_at', 'expires_at', 'completed_at', 'response_status', 'response_headers', 'response_body']))->toBeTrue();
});
