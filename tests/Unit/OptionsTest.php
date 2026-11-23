<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Unit;

use Illuminate\Config\Repository;
use InvalidArgumentException;
use Pharaonic\Laravel\ApiIdempotency\Scopes\AuthenticatedUser;
use Pharaonic\Laravel\ApiIdempotency\Support\Options;
use Pharaonic\Laravel\ApiIdempotency\Tests\TestCase;

final class OptionsTest extends TestCase
{
    public function test_it_reads_the_default_configuration(): void
    {
        $options = Options::fromConfig($this->app['config']);

        $this->assertSame(86400, $options->ttl);
        $this->assertSame(120, $options->lease);
        $this->assertSame(AuthenticatedUser::class, $options->scope);
        $this->assertSame(1048576, $options->maxResponseBytes);
    }

    public function test_a_route_ttl_overrides_the_configured_one(): void
    {
        $this->assertSame(600, Options::fromConfig($this->app['config'], '600')->ttl);
        $this->assertSame(86400, Options::fromConfig($this->app['config'], '')->ttl);
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidConfiguration(): array
    {
        return [
            'ttl zero' => ['ttl', 0, 'The idempotency pharaonic.api-idempotency.ttl must be a whole number of seconds from 1 to 31536000.'],
            'ttl as a float' => ['ttl', 60.5, 'must be a whole number of seconds'],
            'ttl over a year' => ['ttl', 31536001, 'must be a whole number of seconds'],
            'lease over a day' => ['lease', 86401, '[pharaonic.api-idempotency.lease] must be an integer from 1 to 86400.'],
            'scope' => ['scope', '', '[pharaonic.api-idempotency.scope] must be a non-empty string.'],
            'negative response size' => ['max_response_bytes', -1, '[pharaonic.api-idempotency.max_response_bytes] must be an integer from 0 to'],
            'response size as a string' => ['max_response_bytes', '1024', '[pharaonic.api-idempotency.max_response_bytes] must be an integer from 0 to'],
        ];
    }

    /**
     * @dataProvider invalidConfiguration
     */
    public function test_invalid_values_are_rejected_instead_of_corrected(string $key, mixed $value, string $message): void
    {
        $config = new Repository(['pharaonic' => ['api-idempotency' => $this->app['config']->get('pharaonic.api-idempotency')]]);
        $config->set('pharaonic.api-idempotency.'.$key, $value);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Options::fromConfig($config);
    }
}
