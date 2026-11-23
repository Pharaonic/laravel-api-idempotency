<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiIdempotency\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A request the idempotency middleware refused before running the route
 *
 * Renders as {"message": "...", "code": "idempotency_..."} with its HTTP
 * status. Customize it in your exception handler like any HttpException;
 * errorCode() returns one of the constants below.
 */
final class IdempotencyException extends HttpException
{
    public const KEY_MISSING = 'idempotency_key_missing';

    public const KEY_INVALID = 'idempotency_key_invalid';

    public const KEY_CONFLICT = 'idempotency_key_conflict';

    public const IN_PROGRESS = 'idempotency_in_progress';

    public const OUTCOME_UNKNOWN = 'idempotency_outcome_unknown';

    public const UNAUTHENTICATED = 'idempotency_unauthenticated';

    public const REQUEST_UNSUPPORTED = 'idempotency_request_unsupported';

    public const REQUEST_INVALID = 'idempotency_request_invalid';

    public const STORE_UNAVAILABLE = 'idempotency_store_unavailable';

    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(
        private string $errorCode,
        int $status,
        string $message,
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($status, $message, $previous, $headers);
    }

    /**
     * One of the class constants, such as "idempotency_key_conflict"
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->getStatusCode(), $this->getHeaders());
    }

    /**
     * @internal
     */
    public static function keyMissing(): self
    {
        return new self(self::KEY_MISSING, 400, 'The Idempotency-Key header is required.');
    }

    /**
     * @internal
     */
    public static function keyInvalid(): self
    {
        return new self(self::KEY_INVALID, 400, 'The Idempotency-Key header must be one value of 1 to 255 visible ASCII characters.');
    }

    /**
     * @internal
     */
    public static function keyConflict(): self
    {
        return new self(self::KEY_CONFLICT, 409, 'This Idempotency-Key was already used with a different request.');
    }

    /**
     * @internal
     */
    public static function inProgress(int $retryAfter): self
    {
        return new self(self::IN_PROGRESS, 409, 'A request with this idempotency key is still being processed.', ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    /**
     * @internal
     */
    public static function outcomeUnknown(): self
    {
        return new self(self::OUTCOME_UNKNOWN, 409, 'The outcome of the original request with this idempotency key is unknown, so it will not run again.');
    }

    /**
     * @internal
     */
    public static function unauthenticated(): self
    {
        return new self(self::UNAUTHENTICATED, 401, 'Idempotent requests must be authenticated.');
    }

    /**
     * @internal
     */
    public static function unsupportedRequest(): self
    {
        return new self(self::REQUEST_UNSUPPORTED, 415, 'Idempotent requests must have a JSON body or none.');
    }

    /**
     * @internal
     */
    public static function invalidRequest(): self
    {
        return new self(self::REQUEST_INVALID, 400, 'The request body is not valid JSON or is nested too deeply.');
    }

    /**
     * @internal
     */
    public static function storeUnavailable(Throwable $previous): self
    {
        return new self(self::STORE_UNAVAILABLE, 503, 'The idempotency store is unavailable, so the request was not processed.', [], $previous);
    }
}
