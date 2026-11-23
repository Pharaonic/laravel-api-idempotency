<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Keeps every log record, with its context flattened to a string, for assertions.
 */
final class MemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: string}>
     */
    public array $records = [];

    /**
     * @param  array<mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => (string) json_encode(array_map(
            static fn ($value) => $value instanceof \Throwable ? $value->getMessage() : $value,
            $context,
        ))];
    }
}
