<?php

declare(strict_types=1);

return [
    'key_missing' => 'The Idempotency-Key header is required.',
    'key_invalid' => 'The Idempotency-Key header must be one value of 1 to 255 visible ASCII characters.',
    'key_conflict' => 'This Idempotency-Key was already used with a different request.',
    'in_progress' => 'A request with this idempotency key is still being processed.',
    'outcome_unknown' => 'The outcome of the original request with this idempotency key is unknown, so it will not run again.',
    'unauthenticated' => 'Idempotent requests must be authenticated.',
    'request_unsupported' => 'Idempotent requests must have a JSON body or none.',
    'request_invalid' => 'The request body is not valid JSON or is nested too deeply.',
    'store_unavailable' => 'The idempotency store is unavailable, so the request was not processed.',
];
