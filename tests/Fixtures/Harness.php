<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

/**
 * Builds the fixture application outside a test run, for child processes
 */
final class Harness extends TestCase {}
