<?php

namespace Wolvy;

/**
 * One HTTP answer from the API: status, headers (names lower-cased) and the raw body.
 * `$client->getLastResponse()` returns the last one; an ApiException carries its own.
 */
final class ApiResponse
{
    /** @var int */
    private $status;

    /** @var array<string,string> */
    private $headers;

    /** @var string */
    private $body;

    /** @var array<mixed>|null|false false until decoded */
    private $json = false;

    /** @var int */
    private $attempts;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $headers, string $body, int $attempts = 1)
    {
        $this->status = $status;
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $normalised;
        $this->body = $body;
        $this->attempts = $attempts;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string,string> lower-cased names */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name] : null;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * The body decoded as JSON (associative arrays), or null when it is empty or not JSON.
     *
     * @return array<mixed>|null
     */
    public function getJson(): ?array
    {
        if ($this->json === false) {
            $decoded = $this->body === '' ? null : json_decode($this->body, true);
            $this->json = is_array($decoded) ? $decoded : null;
        }
        return $this->json;
    }

    /** X-Request-Id: the `req_…` id of this request. */
    public function getRequestId(): ?string
    {
        return $this->getHeader('x-request-id');
    }

    public function getRateLimit(): ?RateLimit
    {
        return RateLimit::fromHeaders($this->headers);
    }

    /** True when the API answered from its idempotency store (header Idempotent-Replay: true). */
    public function isIdempotentReplay(): bool
    {
        return strtolower((string) $this->getHeader('idempotent-replay')) === 'true';
    }

    /** How many HTTP attempts it took to get this answer (1 = no retry). */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /** @internal */
    public function withAttempts(int $attempts): self
    {
        $clone = clone $this;
        $clone->attempts = $attempts;
        return $clone;
    }
}
