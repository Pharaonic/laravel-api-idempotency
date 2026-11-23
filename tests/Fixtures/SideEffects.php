<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Closure;

/**
 * Counts the fixture actions that ran, in this process and, through a ledger
 * file, across processes. Can slow an action down, kill its process, or run a
 * hook while it runs.
 */
final class SideEffects
{
    public static int $count = 0;

    public static ?Closure $during = null;

    public static function reset(): void
    {
        self::$count = 0;
        self::$during = null;
    }

    public static function record(string $action): int
    {
        $id = ++self::$count;

        $ledger = getenv('IDEMPOTENCY_TEST_LEDGER');

        if (is_string($ledger) && $ledger !== '') {
            file_put_contents($ledger, $action."\n", FILE_APPEND | LOCK_EX);
        }

        $sleep = getenv('IDEMPOTENCY_TEST_SLEEP_MS');

        if (is_string($sleep) && $sleep !== '') {
            usleep((int) $sleep * 1000);
        }

        if (getenv('IDEMPOTENCY_TEST_KILL') === '1' && function_exists('posix_kill')) {
            posix_kill(getmypid(), 9);
        }

        if (self::$during !== null) {
            (self::$during)();
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    public static function ledger(string $path): array
    {
        return is_file($path) ? array_values(array_filter(explode("\n", (string) file_get_contents($path)))) : [];
    }
}
