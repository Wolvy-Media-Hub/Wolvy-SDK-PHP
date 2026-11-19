<?php

namespace Wolvy\Exception;

/**
 * HTTP 500 `internal_error`, 503 `service_unavailable`, or a 502/504 from a proxy, once the SDK's
 * retries are used up. Quote getRequestId() to support if it persists.
 */
class ServerException extends ApiException
{
}
