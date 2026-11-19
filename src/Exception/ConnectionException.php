<?php

namespace Wolvy\Exception;

/**
 * The request did not get an HTTP answer: DNS, TCP or TLS failed, or it timed out. Thrown after
 * the SDK's own retries are used up. A POST carried an Idempotency-Key, so retrying it yourself
 * with the same key is safe.
 */
class ConnectionException extends \RuntimeException implements WolvyException
{
    /** @var int */
    private $curlErrno;

    public function __construct(string $message, int $curlErrno = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $curlErrno, $previous);
        $this->curlErrno = $curlErrno;
    }

    /** The cURL error number (0 when not from cURL). */
    public function getCurlErrno(): int
    {
        return $this->curlErrno;
    }

    /** True when the connection or the whole request ran out of time. */
    public function isTimeout(): bool
    {
        return $this->curlErrno === 28; // CURLE_OPERATION_TIMEDOUT
    }
}
