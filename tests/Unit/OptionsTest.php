<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Pharaonic\Laravel\ApiIdempotency\Scopes\AuthenticatedUser;
use Pharaonic\Laravel\ApiIdempotency\Support\Options;

test('it reads the default configuration', function () {
    $options = Options::fromConfig($this->app['config']);

    expect($options->ttl)->toBe(86400);
    expect($options->lease)->toBe(120);
    expect($options->scope)->toBe(AuthenticatedUser::class);
    expect($options->maxResponseBytes)->toBe(1048576);
});

test('a route ttl overrides the configured one', function () {
    expect(Options::fromConfig($this->app['config'], '600')->ttl)->toBe(600);
    expect(Options::fromConfig($this->app['config'], '')->ttl)->toBe(86400);
});

dataset('invalidConfiguration', function () {
    return [
        'ttl zero' => ['ttl', 0, 'The idempotency pharaonic.api-idempotency.ttl must be a whole number of seconds from 1 to 31536000.'],
        'ttl as a float' => ['ttl', 60.5, 'must be a whole number of seconds'],
        'ttl over a year' => ['ttl', 31536001, 'must be a whole number of seconds'],
        'lease over a day' => ['lease', 86401, '[pharaonic.api-idempotency.lease] must be an integer from 1 to 86400.'],
        'scope' => ['scope', '', '[pharaonic.api-idempotency.scope] must be a non-empty string.'],
        'negative response size' => ['max_response_bytes', -1, '[pharaonic.api-idempotency.max_response_bytes] must be an integer from 0 to'],
        'response size as a string' => ['max_response_bytes', '1024', '[pharaonic.api-idempotency.max_response_bytes] must be an integer from 0 to'],
    ];
});

test('invalid values are rejected instead of corrected', function (string $key, mixed $value, string $message) {
    $config = new Repository(['pharaonic' => ['api-idempotency' => $this->app['config']->get('pharaonic.api-idempotency')]]);
    $config->set('pharaonic.api-idempotency.'.$key, $value);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    Options::fromConfig($config);
})->with('invalidConfiguration');
