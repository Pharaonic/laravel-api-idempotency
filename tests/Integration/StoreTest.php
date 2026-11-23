<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Integration;

use Pharaonic\Laravel\ApiIdempotency\Support\Record;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class StoreTest extends TestCase
{
    /**
     * @dataProvider backends
     */
    public function test_only_the_first_reservation_of_a_key_wins(string $backend): void
    {
        $store = $this->storeFor($backend);
        $first = $this->storeRecord('owner-a');

        $this->assertNull($store->reserve($first, self::START));

        $existing = $store->reserve($this->storeRecord('owner-b', 'other-fingerprint'), self::START + 1);
        $this->assertInstanceOf(Record::class, $existing);
        $this->assertSame('owner-a', $existing->owner);
        $this->assertSame(Record::PROCESSING, $existing->state);
        $this->assertSame(str_repeat('f', 64), $existing->fingerprint);
        $this->assertSame(self::START + 120, $existing->leaseExpiresAt);
        $this->assertSame(self::START + 600, $existing->expiresAt);
        $this->assertSame('POST', $existing->method);
        $this->assertSame('/orders', $existing->route);

        $this->assertNull($store->reserve($this->storeRecord('owner-c', 'other', 'another-key'), self::START), 'Other keys are independent.');
    }

    /**
     * @dataProvider backends
     */
    public function test_a_completed_record_keeps_its_response_until_it_expires(string $backend): void
    {
        $store = $this->storeFor($backend);
        $record = $this->storeRecord('owner-a');
        $store->reserve($record, self::START);

        $this->assertTrue($store->replace('owner-a', $record->complete(self::START + 5, 600, 201, ['content-type' => ['application/json'], 'location' => ['/orders/1']], '{"id":1,"name":"é"}')));

        $completed = $store->reserve($this->storeRecord('owner-b'), self::START + 604);
        $this->assertInstanceOf(Record::class, $completed);
        $this->assertSame(Record::COMPLETED, $completed->state);
        $this->assertSame(201, $completed->status);
        $this->assertSame(['content-type' => ['application/json'], 'location' => ['/orders/1']], $completed->headers);
        $this->assertSame('{"id":1,"name":"é"}', $completed->body);
        $this->assertSame(self::START + 5, $completed->completedAt);
        $this->assertSame(self::START + 605, $completed->expiresAt);

        $this->assertNull($store->reserve($this->storeRecord('owner-b'), self::START + 605), 'At its expiry the key is new.');
    }

    /**
     * @dataProvider backends
     */
    public function test_an_empty_body_survives_storage(string $backend): void
    {
        $store = $this->storeFor($backend);
        $record = $this->storeRecord('owner-a');
        $store->reserve($record, self::START);
        $store->replace('owner-a', $record->complete(self::START, 600, 204, [], ''));

        $completed = $store->reserve($this->storeRecord('owner-b'), self::START + 1);
        $this->assertInstanceOf(Record::class, $completed);
        $this->assertSame(204, $completed->status);
        $this->assertSame('', $completed->body);
        $this->assertSame([], $completed->headers);
    }

    /**
     * @dataProvider backends
     */
    public function test_only_the_owner_of_a_processing_record_can_change_it(string $backend): void
    {
        $store = $this->storeFor($backend);
        $record = $this->storeRecord('owner-a');
        $store->reserve($record, self::START);

        $this->assertFalse($store->replace('owner-b', $record->unknown(self::START, 600)));
        $this->assertFalse($store->release($record->key, 'owner-b'));

        // A takeover fences the old owner out.
        $takeover = $this->storeRecord('owner-b');
        $this->assertTrue($store->replace('owner-a', $takeover));
        $this->assertFalse($store->replace('owner-a', $record->complete(self::START, 600, 201, [], '{}')));
        $this->assertFalse($store->release($record->key, 'owner-a'));

        // Finished records are final.
        $this->assertTrue($store->replace('owner-b', $takeover->complete(self::START, 600, 201, [], '{"by":"b"}')));
        $this->assertFalse($store->replace('owner-b', $takeover->unknown(self::START, 600)));
        $this->assertFalse($store->release($record->key, 'owner-b'));

        $stored = $store->reserve($this->storeRecord('owner-c'), self::START + 1);
        $this->assertInstanceOf(Record::class, $stored);
        $this->assertSame('{"by":"b"}', $stored->body);
    }

    /**
     * @dataProvider backends
     */
    public function test_a_released_key_can_be_reserved_again(string $backend): void
    {
        $store = $this->storeFor($backend);
        $store->reserve($this->storeRecord('owner-a'), self::START);

        $this->assertTrue($store->release($this->storeRecord('owner-a')->key, 'owner-a'));
        $this->assertNull($store->reserve($this->storeRecord('owner-b'), self::START));
    }

    public function test_redis_records_expire_by_themselves_with_their_ttl(): void
    {
        $store = $this->storeFor('phpredis');
        $record = $this->storeRecord('owner-a');
        $store->reserve($record, self::START);
        $redis = $this->app['redis']->connection();
        $key = config('pharaonic.api-idempotency.store.prefix').$record->key;

        $this->assertEqualsWithDelta(600000, $redis->pttl($key), 2000);

        $store->replace('owner-a', $record->complete(self::START, 86400, 201, [], '{}'));
        $this->assertEqualsWithDelta(86400000, $redis->pttl($key), 2000);
    }
}
