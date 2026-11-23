# Contributing

Thanks for contributing to this Pharaonic package.

## Branches

Each supported Laravel major has its own line: `6.x`, `7.x`, and so on up to the newest Laravel release. All lines carry the same package code; only their PHP range and Laravel constraints differ. A line only uses syntax and APIs available on the lowest PHP and Laravel version it supports. `develop` follows the newest line.

```text
6.x  → Laravel 6
7.x  → Laravel 7
8.x  → Laravel 8
...
13.x → Laravel 13
```

Open fixes against the oldest affected line; maintainers port them to the other lines.

## Before coding

- Target the correct Laravel line.
- Keep changes focused.
- Prefer native Laravel capabilities over new abstractions/dependencies.
- Keep framework-independent logic in the underlying `pharaonic/php-*` library when the package builds on one.
- Preserve public compatibility; configuration keys, publish tags, and Blade/Artisan names are public API.

## Local quality gate

Install dependencies and run:

```bash
composer check
```

The package expects formatting, static analysis, tests, Composer validation, and security audit to pass.

Integration tests also run against MySQL, PostgreSQL and Redis when you point them at throwaway servers (their tables are dropped and recreated):

```bash
export IDEMPOTENCY_TEST_MYSQL='{"host":"127.0.0.1","port":3306,"username":"root","password":"secret","database":"idempotency"}'
export IDEMPOTENCY_TEST_PGSQL='{"host":"127.0.0.1","port":5432,"username":"postgres","password":"secret","database":"idempotency"}'
export IDEMPOTENCY_TEST_REDIS_HOST=127.0.0.1:6379
composer test
```

Without them those tests are skipped; SQLite always runs.

## Pull requests

Keep the PR focused, target the line you started from, and explain what changed and why. Public API additions should be deliberate and small.

Security vulnerabilities must follow `SECURITY.md`, not a public PR or issue.
