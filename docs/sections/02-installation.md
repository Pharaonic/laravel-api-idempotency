## Installation

Install the package with Composer and run its migration.

### Requirements

- PHP 8.0, 8.1 or 8.2
- Laravel 9.33 or later 9.x releases
- A database supported by Laravel (MySQL, MariaDB, PostgreSQL, SQLite) or Redis (phpredis or predis)

### Composer Installation

```bash title="Terminal" no-line-numbers
composer require pharaonic/laravel-api-idempotency
```

The service provider is registered automatically.

### Run Migrations

The `idempotency_keys` table is needed only by the `database` store, the default. The package never runs its migration on its own: publish it, then migrate.

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=api-idempotency-migrations
php artisan migrate
```

Using Redis? Skip this step and set `IDEMPOTENCY_STORE=redis` (see [Stores](#stores)).

### Publish Configuration

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=api-idempotency-config
```

The file lands in `config/pharaonic/api-idempotency.php`.

### Schedule Pruning

Expired database records are deleted by a command. Schedule it in `app/Console/Kernel.php`:

```php title="app/Console/Kernel.php"
protected function schedule(Schedule $schedule)
{
    $schedule->command('idempotency:prune --apply')->hourly();
}
```

:::success Installation Complete
Add `->idempotent()` to an authenticated POST route and you are done.
:::
