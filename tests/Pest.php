<?php

declare(strict_types=1);

use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Integration', 'Unit');

// SQLite always; MySQL, PostgreSQL and Redis when their servers are configured.
dataset('backends', fn (): array => TestCase::backends());
