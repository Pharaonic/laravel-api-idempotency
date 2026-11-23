<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Tests\Fixtures;

use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * "Authorization: Bearer user-<id>" signs in user <id>
 *
 * Reads the current request on every call and caches nothing, so one test
 * application can serve several users in a row.
 */
final class TokenGuard implements Guard
{
    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        /** @var Request $request */
        $request = Container::getInstance()->make('request');

        return preg_match('/\Auser-([0-9]+)\z/', (string) $request->bearerToken(), $match) === 1 ? new GenericUser(['id' => (int) $match[1]]) : null;
    }

    public function id(): mixed
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * @param  array<mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->check();
    }

    public function setUser(Authenticatable $user): void {}
}
