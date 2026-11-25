<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Orchestra\Testbench\Exceptions\Handler;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;

/**
 * An application handler with its own render callback, registered in register() as Laravel 8 to 10 do.
 */
class RenderingExceptionHandler extends Handler
{
    public function register(): void
    {
        $this->renderable(fn (IdempotencyException $exception) => response()->json(['error' => $exception->errorCode()], $exception->getStatusCode()));
    }
}
