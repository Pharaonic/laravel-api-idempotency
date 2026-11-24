<?php

declare(strict_types=1);

use Pharaonic\Laravel\ApiIdempotency\ApiIdempotencyServiceProvider;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;

/**
 * @return list<IdempotencyException>
 */
function idempotencyExceptions(): array
{
    return [
        IdempotencyException::keyMissing(),
        IdempotencyException::keyInvalid(),
        IdempotencyException::keyConflict(),
        IdempotencyException::inProgress(5),
        IdempotencyException::outcomeUnknown(),
        IdempotencyException::unauthenticated(),
        IdempotencyException::unsupportedRequest(),
        IdempotencyException::invalidRequest(),
        IdempotencyException::storeUnavailable(new RuntimeException('down')),
    ];
}

test('the english lines are the messages of the exceptions', function () {
    foreach (idempotencyExceptions() as $exception) {
        expect($exception->localizedMessage())->toBe($exception->getMessage());
    }
});

test('every exception has a line in every locale', function () {
    $this->app->setLocale('ar');

    foreach (idempotencyExceptions() as $exception) {
        expect($exception->localizedMessage())->not->toBe($exception->getMessage());
    }
});

test('refused requests get the message of the current locale and the same code', function () {
    $this->app->setLocale('ar');

    $this->idempotent('/orders', ['quantity' => 2], null)
        ->assertStatus(400)
        ->assertExactJson(['message' => 'الترويسة Idempotency-Key مطلوبة.', 'code' => 'idempotency_key_missing']);
    $this->assertNothingRan();
});

test('getMessage stays english and unknown locales fall back to english', function () {
    $this->app->setLocale('xx');
    $exception = IdempotencyException::keyConflict();

    expect($exception->localizedMessage())->toBe('This Idempotency-Key was already used with a different request.');

    $this->app->setLocale('ar');

    expect($exception->getMessage())->toBe('This Idempotency-Key was already used with a different request.');
});

test('the application can override a line', function () {
    $this->app['translator']->addLines(['messages.key_missing' => 'Send an Idempotency-Key.'], 'en', 'api-idempotency');

    $this->idempotent('/orders', ['quantity' => 2], null)->assertExactJson(['message' => 'Send an Idempotency-Key.', 'code' => 'idempotency_key_missing']);
});

test('the translations are published with the four tags', function (string $tag) {
    expect(ApiIdempotencyServiceProvider::pathsToPublish(ApiIdempotencyServiceProvider::class, $tag))->toContain(lang_path('vendor/api-idempotency'));
})->with(['pharaonic', 'laravel-api-idempotency', 'pharaonic-translations', 'api-idempotency-translations']);

test('every locale has the english keys', function () {
    $english = require __DIR__.'/../../resources/lang/en/messages.php';

    foreach (glob(__DIR__.'/../../resources/lang/*/messages.php') ?: [] as $file) {
        expect(array_keys(require $file))->toBe(array_keys($english));
    }
});
