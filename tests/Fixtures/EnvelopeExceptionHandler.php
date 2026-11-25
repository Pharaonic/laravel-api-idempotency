<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Http\Request;
use Orchestra\Testbench\Exceptions\Handler;
use Throwable;

/**
 * An application handler with one callback for every exception, like a package
 * that renders all API errors in its own envelope; it declines web requests.
 */
class EnvelopeExceptionHandler extends Handler
{
    public function register(): void
    {
        $this->renderable(function (Throwable $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json(['envelope' => get_class($exception)], 422);
        });
    }
}
