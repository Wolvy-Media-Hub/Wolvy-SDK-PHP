<?php

namespace Wolvy\Exception;

use Wolvy\ApiResponse;
use Wolvy\RateLimit;

/**
 * The API answered with an error. Every error the Developer API sends has the shape
 *
 *     {"error": {"type": "...", "code": "...", "message": "...", "param": "...", "request_id": "req_..."}}
 *
 * `code` is the stable machine-readable contract: branch on getErrorCode(), not on the message.
 * The subclass tells the HTTP status family apart; getHttpStatus() gives the exact one.
 * getCode() (the PHP exception code) is the HTTP status too.
 */
class ApiException extends \RuntimeException implements WolvyException
{
    /** @var ApiResponse */
    private $response;

    /** @var string|null */
    private $errorCode;

    /** @var string|null */
    private $errorType;

    /** @var string|null */
    private $param;

    /** @var string|null */
    private $requestId;

    public function __construct(
        string $message,
        ApiResponse $response,
        ?string $errorCode = null,
        ?string $errorType = null,
        ?string $param = null,
        ?string $requestId = null
    ) {
        parent::__construct($message, $response->getStatus());
        $this->response = $response;
        $this->errorCode = $errorCode;
        $this->errorType = $errorType;
        $this->param = $param;
        $this->requestId = $requestId !== null ? $requestId : $response->getRequestId();
    }

    /**
     * Builds the right subclass for an error answer.
     */
    public static function fromResponse(ApiResponse $response): self
    {
        $status = $response->getStatus();
        $body = $response->getJson();
        $error = (is_array($body) && isset($body['error']) && is_array($body['error'])) ? $body['error'] : null;

        $code = $type = $param = $requestId = null;
        if ($error !== null) {
            $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
            $type = isset($error['type']) && is_string($error['type']) ? $error['type'] : null;
            $param = isset($error['param']) && is_string($error['param']) ? $error['param'] : null;
            $requestId = isset($error['request_id']) && is_string($error['request_id']) ? $error['request_id'] : null;
            $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== ''
                ? $error['message']
                : 'The Wolvy API answered HTTP ' . $status . '.';
        } else {
            $message = 'The Wolvy API answered HTTP ' . $status . ' without a JSON error body.';
        }

        switch (true) {
            case $status === 400 || $status === 405:
                $class = InvalidRequestException::class;
                break;
            case $status === 401:
                $class = AuthenticationException::class;
                break;
            case $status === 403:
                $class = PermissionException::class;
                break;
            case $status === 404:
                $class = NotFoundException::class;
                break;
            case $status === 409:
                $class = ConflictException::class;
                break;
            case $status === 422:
                $class = UnprocessableEntityException::class;
                break;
            case $status === 429:
                $class = RateLimitException::class;
                break;
            case $status >= 500:
                $class = ServerException::class;
                break;
            default:
                $class = self::class;
        }

        return new $class($message, $response, $code, $type, $param, $requestId);
    }

    /** The HTTP status (400, 404, 429, 500...). */
    public function getHttpStatus(): int
    {
        return $this->response->getStatus();
    }

    /**
     * The stable error code: `video_not_found`, `rate_limit_exceeded`, `invalid_parameter`...
     * Null only when the answer was not a Wolvy error body (a proxy's HTML page, for instance).
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The error type: `invalid_request_error`, `authentication_error`, `permission_error`,
     * `rate_limit_error` or `api_error`.
     */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /** The request field or query parameter the error is about, when there is one. */
    public function getParam(): ?string
    {
        return $this->param;
    }

    /** `req_…`: quote it to Wolvy support. From the body, else the X-Request-Id header. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * The X-RateLimit-* numbers and Retry-After of this answer. Every answer after the key was
     * accepted carries them, errors included; null before that (401, unknown endpoint).
     */
    public function getRateLimit(): ?RateLimit
    {
        return $this->response->getRateLimit();
    }

    /** Seconds the API asked to wait (Retry-After), when it said. */
    public function getRetryAfter(): ?int
    {
        $rateLimit = $this->response->getRateLimit();
        return $rateLimit !== null ? $rateLimit->retryAfter : null;
    }

    /** The full answer: status, headers and raw body. */
    public function getResponse(): ApiResponse
    {
        return $this->response;
    }
}
