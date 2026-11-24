<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;
use Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures\RenderingExceptionHandler;

test('the package renders a refusal when the application does not', function () {
    $this->idempotent('/orders', ['quantity' => 2], null)
        ->assertStatus(400)
        ->assertExactJson(['message' => 'The Idempotency-Key header is required.', 'code' => 'idempotency_key_missing']);
});

test('a render callback of the application wins over the package', function () {
    $this->app->singleton(ExceptionHandler::class, RenderingExceptionHandler::class);
    $this->app->forgetInstance(ExceptionHandler::class);
    $this->app->make(ExceptionHandler::class);

    $this->idempotent('/orders', ['quantity' => 2], null)->assertStatus(400)->assertExactJson(['error' => 'idempotency_key_missing']);
    $this->assertNothingRan();
});

test('render without arguments is the package response and with the request defers to the handler', function () {
    $exception = IdempotencyException::inProgress(5);

    expect($exception->render())->toBeInstanceOf(JsonResponse::class)
        ->and($exception->render()->getData(true))->toBe(['message' => 'A request with this idempotency key is still being processed.', 'code' => 'idempotency_in_progress'])
        ->and($exception->render()->headers->get('Retry-After'))->toBe('5')
        ->and($exception->render(request()))->toBeNull()
        ->and($exception->response()->getData(true))->toBe($exception->render()->getData(true));
});
