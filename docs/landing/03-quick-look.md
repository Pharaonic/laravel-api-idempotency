---
view: components.packages.quick-look
title: A quick look
subtitle: Mark a route idempotent; clients send an Idempotency-Key header.
file: routes/api.php
language: php
code: |
  use App\Http\Controllers\OrderController;
  use App\Http\Controllers\PaymentController;
  use Illuminate\Support\Facades\Route;

  Route::middleware('auth:api')->group(function () {
      Route::post('orders', [OrderController::class, 'store'])->idempotent();

      // Keep payment replays for 7 days.
      Route::post('payments', [PaymentController::class, 'store'])
          ->idempotent(ttl: 604800);
  });
---
