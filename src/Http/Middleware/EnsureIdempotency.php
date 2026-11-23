<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pharaonic\Laravel\ApiIdempotency\Contracts\ScopeResolver;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestRejected;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReplayed;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestReserved;
use Pharaonic\Laravel\ApiIdempotency\Events\IdempotentRequestUnresolved;
use Pharaonic\Laravel\ApiIdempotency\Exceptions\IdempotencyException;
use Pharaonic\Laravel\ApiIdempotency\Stores\Store;
use Pharaonic\Laravel\ApiIdempotency\Stores\Stores;
use Pharaonic\Laravel\ApiIdempotency\Support\Options;
use Pharaonic\Laravel\ApiIdempotency\Support\Record;
use Pharaonic\Laravel\ApiIdempotency\Support\RequestFingerprint;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Runs a route at most once per idempotency key, and replays its stored response
 *
 * Safe methods (GET, HEAD, OPTIONS, TRACE) pass through. Every other request
 * needs a valid key and a scope; its key is reserved atomically before the
 * route runs, and the request is refused when the key holds another request,
 * a running one, or one whose outcome is unknown.
 *
 * Parameter (positional, like any middleware): ttl in seconds. Use
 * Route::...->idempotent() rather than writing it by hand.
 */
final class EnsureIdempotency
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    private const KEY_HEADER = 'Idempotency-Key';

    private const REPLAYED_HEADER = 'Idempotency-Replayed';

    /**
     * Response headers stored and replayed with the body; never credentials,
     * cookies, or headers that belong to the original connection.
     */
    private const REPLAYED_HEADERS = ['content-type', 'location', 'content-location', 'etag', 'last-modified'];

    public function __construct(
        private Container $container,
        private Repository $config,
        private Stores $stores,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The middleware string for a route, validated when the route is defined
     */
    public static function using(mixed $ttl = null): string
    {
        return $ttl === null ? self::class : self::class.':'.Options::ttl($ttl, 'route ttl');
    }

    public function handle(Request $request, Closure $next, ?string $ttl = null): mixed
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $options = Options::fromConfig($this->config, $ttl);
        $clientKey = $this->clientKey($request);
        $scope = $this->scope($request, $options);
        $fingerprint = RequestFingerprint::request($request);
        $route = $this->routeIdentity($request);
        $key = RequestFingerprint::key($scope, $request->getMethod(), $route, $clientKey);
        $store = $this->stores->get();
        $now = $this->now();
        $record = Record::processing($key, $fingerprint, bin2hex(random_bytes(16)), $now, $options->lease, $options->ttl, $request->getMethod(), $route);

        try {
            $existing = $store->reserve($record, $now);
        } catch (Throwable $e) {
            $this->logger->error('Idempotency store failed to reserve a key; the request was refused.', $this->context($record, $e));

            throw IdempotencyException::storeUnavailable($e);
        }

        if ($existing !== null) {
            return $this->answerExisting($request, $existing, $record, $now);
        }

        $this->events->dispatch(new IdempotentRequestReserved($request, $key));

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->unresolved($request, $store, $record, $options, 'exception');

            throw $e;
        }

        $this->finish($request, $store, $record, $options, $response);

        return $response;
    }

    /**
     * Replay the stored response, or refuse because of the record that holds the key
     */
    private function answerExisting(Request $request, Record $existing, Record $record, int $now): SymfonyResponse
    {
        if (! hash_equals($existing->fingerprint, $record->fingerprint)) {
            $this->reject($request, $record, IdempotencyException::keyConflict());
        }

        if ($existing->state === Record::COMPLETED) {
            $this->events->dispatch(new IdempotentRequestReplayed($request, $record->key));

            return $this->replay($existing);
        }

        if ($existing->state === Record::PROCESSING && $existing->leaseExpiresAt > $now) {
            $this->reject($request, $record, IdempotencyException::inProgress($existing->leaseExpiresAt - $now));
        }

        $this->reject($request, $record, IdempotencyException::outcomeUnknown());
    }

    /**
     * @return never
     */
    private function reject(Request $request, Record $record, IdempotencyException $exception): void
    {
        $this->events->dispatch(new IdempotentRequestRejected($request, $record->key, $exception->errorCode()));

        throw $exception;
    }

    private function replay(Record $record): SymfonyResponse
    {
        $response = new Response($record->body ?? '', $record->status ?? 200, $record->headers);
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    /**
     * Store the response, release the key for a retry, or lock it as unknown
     */
    private function finish(Request $request, Store $store, Record $record, Options $options, mixed $response): void
    {
        if (! $response instanceof SymfonyResponse) {
            $this->unresolved($request, $store, $record, $options, 'unstorable_response');

            return;
        }

        $status = $response->getStatusCode();
        $stored = $status >= 200 && $status < 300 ? $this->storable($response, $options) : null;

        try {
            if ($stored !== null) {
                $done = $store->replace($record->owner, $record->complete($this->now(), $options->ttl, $status, $stored['headers'], $stored['body']));
            } elseif ($status >= 400 && $status < 500) {
                $done = $store->release($record->key, $record->owner);
            } else {
                $this->unresolved($request, $store, $record, $options, $status >= 500 ? 'server_error' : 'unstorable_response');

                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('Idempotency store failed to record a finished request; its key stays locked until it expires.', $this->context($record, $e));
            $this->events->dispatch(new IdempotentRequestUnresolved($request, $record->key, 'store_failed'));

            return;
        }

        if (! $done) {
            $this->logger->warning('Idempotent request finished after its record expired; its outcome was not stored.', $this->context($record));
        }
    }

    /**
     * Lock the key as an unknown outcome: the route may have had side effects
     */
    private function unresolved(Request $request, Store $store, Record $record, Options $options, string $reason): void
    {
        try {
            $store->replace($record->owner, $record->unknown($this->now(), $options->ttl));
        } catch (Throwable $e) {
            $this->logger->error('Idempotency store failed to record an unknown outcome; the key stays locked until its lease expires.', $this->context($record, $e));
        }

        $this->logger->warning('Idempotent request ended with an unknown outcome; its key stays locked until it expires.', $this->context($record) + ['reason' => $reason]);
        $this->events->dispatch(new IdempotentRequestUnresolved($request, $record->key, $reason));
    }

    /**
     * The status, allowed headers and body to replay, or null when the response cannot be replayed
     *
     * @return array{headers: array<string, list<string>>, body: string}|null
     */
    private function storable(SymfonyResponse $response, Options $options): ?array
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $body = $response->getContent();

        if (! is_string($body) || strlen($body) > $options->maxResponseBytes) {
            return null;
        }

        if ($body !== '') {
            $type = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));

            if (($type !== 'application/json' && ! str_ends_with($type, '+json')) || preg_match('//u', $body) !== 1) {
                return null;
            }
        }

        $headers = [];

        foreach (self::REPLAYED_HEADERS as $name) {
            $values = array_values(array_filter($response->headers->all($name), 'is_string'));

            if ($values !== []) {
                $headers[$name] = $values;
            }
        }

        return ['headers' => $headers, 'body' => $body];
    }

    private function clientKey(Request $request): string
    {
        $values = $request->headers->all(self::KEY_HEADER);

        if ($values === [] || $values === [null] || $values === ['']) {
            throw IdempotencyException::keyMissing();
        }

        if (count($values) !== 1 || ! is_string($values[0]) || preg_match('/\A[\x21-\x7E]{1,255}\z/', $values[0]) !== 1) {
            throw IdempotencyException::keyInvalid();
        }

        return $values[0];
    }

    private function scope(Request $request, Options $options): string
    {
        $resolver = $this->container->make($options->scope);

        if (! $resolver instanceof ScopeResolver) {
            throw new InvalidArgumentException('[pharaonic.api-idempotency.scope] must name a class implementing '.ScopeResolver::class.'.');
        }

        $scope = $resolver->resolve($request);

        if ($scope === null || $scope === '') {
            throw IdempotencyException::unauthenticated();
        }

        return $scope;
    }

    /**
     * The route's domain and URI pattern, so a key never crosses routes
     */
    private function routeIdentity(Request $request): string
    {
        // The resolver, not route(): newer releases type route() as never null,
        // but the middleware may also run outside a matched route.
        $route = call_user_func($request->getRouteResolver());

        if ($route instanceof Route) {
            return ($route->getDomain() ?? '').'/'.$route->uri();
        }

        return $request->getHost().$request->getPathInfo();
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /**
     * Log context without the client's key, scope or body
     *
     * A store exception is reduced to its class, code and, outside the
     * database, its message: a QueryException's message holds the SQL with its
     * bindings, which are the stored response and the owner token.
     *
     * @return array<string, mixed>
     */
    private function context(Record $record, ?Throwable $e = null): array
    {
        return array_filter([
            'key' => substr($record->key, 0, 16),
            'method' => $record->method,
            'route' => $record->route,
            'exception' => $e === null ? null : get_class($e),
            'code' => $e === null ? null : $e->getCode(),
            'error' => $e === null || $e instanceof QueryException ? null : $e->getMessage(),
        ], static fn ($value): bool => $value !== null);
    }
}
