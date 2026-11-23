<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * The fixture application's routes, loaded like an application's own: from
 * the route cache when it exists, otherwise from map().
 */
final class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        parent::register();

        Auth::extend('fixture', fn (): TokenGuard => new TokenGuard);
    }

    public function map(): void
    {
        Route::middleware(['auth:fixture', SubstituteBindings::class])->group(function (): void {
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store')->idempotent();
            Route::put('orders', [OrderController::class, 'store'])->name('orders.replace')->idempotent();
            Route::post('orders/copy', [OrderController::class, 'store'])->name('orders.copy')->idempotent();
            Route::post('orders/short', [OrderController::class, 'store'])->name('orders.short')->idempotent(ttl: 600);
            Route::post('orders/{order}/refunds', [OrderController::class, 'refund'])->name('orders.refunds')->idempotent();
            Route::post('no-content', [OrderController::class, 'noContent'])->idempotent();
            Route::post('invalid', [OrderController::class, 'invalid'])->idempotent();
            Route::post('fail', [OrderController::class, 'fail'])->idempotent();
            Route::post('html', [OrderController::class, 'html'])->idempotent();
            Route::post('stream', [OrderController::class, 'stream'])->idempotent();
            Route::post('large', [OrderController::class, 'large'])->idempotent();
            Route::post('redirect', [OrderController::class, 'redirect'])->idempotent();
            Route::apiResource('resources', ResourceController::class)->idempotent();

            Route::middleware('idempotent:600')->group(function (): void {
                Route::post('aliased', [OrderController::class, 'store'])->name('aliased');
            });
        });

        Route::post('anonymous', [OrderController::class, 'store'])->idempotent();
        Route::post('late-auth', [OrderController::class, 'store'])->idempotent()->middleware('auth:fixture');
    }
}
