---
view: components.packages.quick-look
title: A quick look
subtitle: Mark a route idempotent; clients send an Idempotency-Key header.
file: routes/api.php
language: php
code: |
  use Illuminate\Support\Facades\Route;

  Route::middleware('auth:api')->group(function () {
      Route::post('orders', 'OrderController@store')->idempotent();

      // Keep payment replays for 7 days.
      Route::post('payments', 'PaymentController@store')
          ->idempotent(ttl: 604800);
  });
---
