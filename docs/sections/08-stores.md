## Stores

`store.driver` picks one of two drivers, and both reserve keys atomically; any other driver throws, because a store without atomic reservation could run a request twice.

```php title="config/pharaonic/api-idempotency.php"
'store' => [
    'driver' => env('IDEMPOTENCY_STORE', 'database'),
    'connection' => env('IDEMPOTENCY_CONNECTION'),
    'table' => 'idempotency_keys',   // database
    'prefix' => 'idempotency:',      // redis
],
```

### Database

The primary key on `storage_key` is the lock: `INSERT ... ON CONFLICT DO NOTHING` (or `INSERT IGNORE` / `INSERT OR IGNORE`) succeeds for exactly one request. Every later change is one conditional `UPDATE` or `DELETE` on the owner token. MySQL, MariaDB and PostgreSQL work across servers; SQLite works within one machine.

Its table comes from the published migration (see [Installation](#installation)); without it, idempotent requests get `503 idempotency_store_unavailable`.

Expired rows stay in the table until you prune them:

```bash title="Terminal" no-line-numbers
php artisan idempotency:prune            # counts what would be deleted
php artisan idempotency:prune --apply    # deletes expired completed records
php artisan idempotency:prune --apply --unresolved   # also expired unknown and abandoned records
```

The command keeps unresolved records unless you pass `--unresolved`, and says how many are left.

:::tip Use a dedicated connection
Point `IDEMPOTENCY_CONNECTION` at its own connection when your controllers wrap work in transactions on the default one, so a rollback in your code never touches the idempotency record.
:::

### Redis

Set `IDEMPOTENCY_STORE=redis`, and `IDEMPOTENCY_CONNECTION` to a connection of `database.redis` (the default one when empty). Each record is a hash changed only by Lua scripts, one key per script, so it also works on Redis Cluster. Keys expire by themselves with `PEXPIRE`, never before their TTL; there is nothing to prune and no migration to publish. Both phpredis and predis work.

:::warning Redis persistence
A Redis restart without persistence (AOF or RDB) forgets every record, and retries then run again. Use a persistent Redis, or the database store, for routes with real side effects.
:::

### Store outages

When the store cannot reserve a key, the request is refused with `503 idempotency_store_unavailable` and your route does not run. There is no option to run unprotected.
