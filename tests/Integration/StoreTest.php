<?php

declare(strict_types=1);

use Pharaonic\Laravel\ApiIdempotency\Support\Record;

test('only the first reservation of a key wins', function (string $backend) {
    $store = $this->storeFor($backend);
    $first = $this->storeRecord('owner-a');

    expect($store->reserve($first, self::START))->toBeNull();

    $existing = $store->reserve($this->storeRecord('owner-b', 'other-fingerprint'), self::START + 1);
    expect($existing)->toBeInstanceOf(Record::class);
    expect($existing->owner)->toBe('owner-a');
    expect($existing->state)->toBe(Record::PROCESSING);
    expect($existing->fingerprint)->toBe(str_repeat('f', 64));
    expect($existing->leaseExpiresAt)->toBe(self::START + 120);
    expect($existing->expiresAt)->toBe(self::START + 600);
    expect($existing->method)->toBe('POST');
    expect($existing->route)->toBe('/orders');

    $this->assertNull($store->reserve($this->storeRecord('owner-c', 'other', 'another-key'), self::START), 'Other keys are independent.');
})->with('backends');

test('a completed record keeps its response until it expires', function (string $backend) {
    $store = $this->storeFor($backend);
    $record = $this->storeRecord('owner-a');
    $store->reserve($record, self::START);

    expect($store->replace('owner-a', $record->complete(self::START + 5, 600, 201, ['content-type' => ['application/json'], 'location' => ['/orders/1']], '{"id":1,"name":"é"}')))->toBeTrue();

    $completed = $store->reserve($this->storeRecord('owner-b'), self::START + 604);
    expect($completed)->toBeInstanceOf(Record::class);
    expect($completed->state)->toBe(Record::COMPLETED);
    expect($completed->status)->toBe(201);
    expect($completed->headers)->toBe(['content-type' => ['application/json'], 'location' => ['/orders/1']]);
    expect($completed->body)->toBe('{"id":1,"name":"é"}');
    expect($completed->completedAt)->toBe(self::START + 5);
    expect($completed->expiresAt)->toBe(self::START + 605);

    $this->assertNull($store->reserve($this->storeRecord('owner-b'), self::START + 605), 'At its expiry the key is new.');
})->with('backends');

test('an empty body survives storage', function (string $backend) {
    $store = $this->storeFor($backend);
    $record = $this->storeRecord('owner-a');
    $store->reserve($record, self::START);
    $store->replace('owner-a', $record->complete(self::START, 600, 204, [], ''));

    $completed = $store->reserve($this->storeRecord('owner-b'), self::START + 1);
    expect($completed)->toBeInstanceOf(Record::class);
    expect($completed->status)->toBe(204);
    expect($completed->body)->toBe('');
    expect($completed->headers)->toBe([]);
})->with('backends');

test('only the owner of a processing record can change it', function (string $backend) {
    $store = $this->storeFor($backend);
    $record = $this->storeRecord('owner-a');
    $store->reserve($record, self::START);

    expect($store->replace('owner-b', $record->unknown(self::START, 600)))->toBeFalse();
    expect($store->release($record->key, 'owner-b'))->toBeFalse();

    // A takeover fences the old owner out.
    $takeover = $this->storeRecord('owner-b');
    expect($store->replace('owner-a', $takeover))->toBeTrue();
    expect($store->replace('owner-a', $record->complete(self::START, 600, 201, [], '{}')))->toBeFalse();
    expect($store->release($record->key, 'owner-a'))->toBeFalse();

    // Finished records are final.
    expect($store->replace('owner-b', $takeover->complete(self::START, 600, 201, [], '{"by":"b"}')))->toBeTrue();
    expect($store->replace('owner-b', $takeover->unknown(self::START, 600)))->toBeFalse();
    expect($store->release($record->key, 'owner-b'))->toBeFalse();

    $stored = $store->reserve($this->storeRecord('owner-c'), self::START + 1);
    expect($stored)->toBeInstanceOf(Record::class);
    expect($stored->body)->toBe('{"by":"b"}');
})->with('backends');

test('a released key can be reserved again', function (string $backend) {
    $store = $this->storeFor($backend);
    $store->reserve($this->storeRecord('owner-a'), self::START);

    expect($store->release($this->storeRecord('owner-a')->key, 'owner-a'))->toBeTrue();
    expect($store->reserve($this->storeRecord('owner-b'), self::START))->toBeNull();
})->with('backends');

test('redis records expire by themselves with their ttl', function () {
    $store = $this->storeFor('phpredis');
    $record = $this->storeRecord('owner-a');
    $store->reserve($record, self::START);
    $redis = $this->app['redis']->connection();
    $key = config('pharaonic.api-idempotency.store.prefix').$record->key;

    $this->assertEqualsWithDelta(600000, $redis->pttl($key), 2000);

    $store->replace('owner-a', $record->complete(self::START, 86400, 201, [], '{}'));
    $this->assertEqualsWithDelta(86400000, $redis->pttl($key), 2000);
});
