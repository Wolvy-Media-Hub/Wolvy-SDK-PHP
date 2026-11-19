<?php

namespace Wolvy\Exception;

/**
 * HTTP 429 `rate_limit_exceeded`. Thrown once the SDK's own retries are used up, or straight away
 * when the API asks to wait longer than the client's `max_retry_delay` (the daily limit).
 * getRetryAfter() says how long.
 */
class RateLimitException extends ApiException
{
}
