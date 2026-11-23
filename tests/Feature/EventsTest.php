<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestRejected;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReplayed;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReserved;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestUnresolved;

test('a reservation and a replay dispatch one event each', function () {
    Event::fake(self::EVENTS);

    $this->idempotent('/orders', ['quantity' => 2], 'secret-client-key');
    Event::assertDispatchedTimes(IdempotentRequestReserved::class, 1);
    Event::assertNotDispatched(IdempotentRequestReplayed::class);

    $this->idempotent('/orders', ['quantity' => 2], 'secret-client-key');
    Event::assertDispatchedTimes(IdempotentRequestReserved::class, 1);
    Event::assertDispatched(IdempotentRequestReplayed::class, static function (IdempotentRequestReplayed $event): bool {
        return preg_match('/\A[0-9a-f]{64}\z/', $event->key) === 1 && $event->request->getPathInfo() === '/orders';
    });
    Event::assertNotDispatched(IdempotentRequestRejected::class);
    Event::assertNotDispatched(IdempotentRequestUnresolved::class);
});

test('a conflict dispatches a rejection with its code', function () {
    Event::fake(self::EVENTS);

    $this->idempotent('/orders', ['quantity' => 2]);
    $this->idempotent('/orders', ['quantity' => 3]);

    Event::assertDispatched(IdempotentRequestRejected::class, static fn (IdempotentRequestRejected $event): bool => $event->reason === 'idempotency_key_conflict');
});

test('an unknown outcome dispatches an unresolved event with its reason', function () {
    Event::fake(self::EVENTS);

    $this->idempotent('/fail');
    $this->idempotent('/html', [], 'key-2');
    $this->idempotent('/fail');

    Event::assertDispatched(IdempotentRequestUnresolved::class, static fn (IdempotentRequestUnresolved $event): bool => $event->reason === 'server_error');
    Event::assertDispatched(IdempotentRequestUnresolved::class, static fn (IdempotentRequestUnresolved $event): bool => $event->reason === 'unstorable_response');
    Event::assertDispatched(IdempotentRequestRejected::class, static fn (IdempotentRequestRejected $event): bool => $event->reason === 'idempotency_outcome_unknown');
});

test('requests refused before reservation dispatch nothing', function () {
    Event::fake(self::EVENTS);

    $this->idempotent('/orders', ['quantity' => 2], null);
    $this->idempotent('/anonymous', ['quantity' => 2], 'key-1', 0);
    $this->getJson('/resources', ['Authorization' => 'Bearer user-1']);

    foreach (self::EVENTS as $event) {
        Event::assertNotDispatched($event);
    }
});
