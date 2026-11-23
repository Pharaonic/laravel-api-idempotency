<p align="center"><a href="https://pharaonic.dev/packages/laravel/api-idempotency" target="_blank"><img src="https://raw.githubusercontent.com/Pharaonic/laravel-api-idempotency/develop/docs/cover.webp"></a></p>

<p align="center">
  <a href="https://php.net" target="_blank"><img src="https://img.shields.io/static/v1?label=PHP&message=8.3%20|%208.4%20|%208.5&color=blue&style=flat-square" alt="PHP Version : 8.3 | 8.4 | 8.5"></a>
  <a href="https://laravel.com" target="_blank"><img src="https://img.shields.io/static/v1?label=Laravel&message=13.x&color=F05340&style=flat-square" alt="Laravel Version : 13.x"></a>
  <img src="https://img.shields.io/static/v1?label=License&message=MIT&color=brightgreen&style=flat-square" alt="License">
  <img src="https://github.com/Pharaonic/laravel-api-idempotency/actions/workflows/build.yml/badge.svg?branch=13.x" alt="Build">
  <br>
  <a href="https://packagist.org/packages/Pharaonic/laravel-api-idempotency" target="_blank"><img src="https://img.shields.io/static/v1?label=Packagist&message=pharaonic/laravel-api-idempotency&color=blue&logo=packagist&logoColor=white" alt="Source"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-api-idempotency" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-api-idempotency/v" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-api-idempotency" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-api-idempotency/downloads" alt="Packagist Downloads"></a>
  <a href="https://pharaonic.dev/packages/laravel/api-idempotency#ai-ready" target="_blank"><img src="https://img.shields.io/static/v1?label=AI%20Ready&message=Laravel%20Boost&color=8B5CF6&style=flat-square" alt="AI Ready: Laravel Boost"></a>
</p>

<h3 align="center">Opt-in Idempotency-Key handling for Laravel routes: retries replay the stored response instead of running twice.</h3>
<br>

## Installation

```bash
composer require pharaonic/laravel-api-idempotency
```

The service provider is registered automatically. The default `database` store needs its table; publish the migration and run it (skip this when you only use Redis):

```bash
php artisan vendor:publish --tag=api-idempotency-migrations
php artisan migrate
```

Publish the configuration when you need to change it:

```bash
php artisan vendor:publish --tag=api-idempotency-config
```

## Usage

```php
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    Route::post('orders', [OrderController::class, 'store'])->idempotent();

    Route::post('payments', [PaymentController::class, 'store'])->idempotent(ttl: 604800);
});
```

Clients send an `Idempotency-Key` header. A retry with the same key and request gets the stored response with `Idempotency-Replayed: true`; the same key with another request, or while the first one still runs, gets `409`. This prevents duplicate execution of one key; it is not exactly-once delivery of external side effects.

## Documentation

Full documentation: [Laravel API Idempotency Documentation](https://pharaonic.dev/packages/laravel/api-idempotency).

## Support & Security

See [SUPPORT.md](SUPPORT.md) and [SECURITY.md](SECURITY.md). Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
