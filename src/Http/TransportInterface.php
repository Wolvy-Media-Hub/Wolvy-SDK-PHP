<?php

namespace Wolvy\Http;

use Wolvy\ApiResponse;
use Wolvy\Exception\ConnectionException;

/**
 * Sends one HTTP request. CurlTransport is the default; pass your own as the client's
 * `transport` option to route requests elsewhere (a proxy-aware client, a test double).
 * Retries, idempotency keys and error mapping happen in the Client, above this layer.
 */
interface TransportInterface
{
    /**
     * @param string                $method  GET, POST, PATCH, PUT or DELETE
     * @param string                $url     absolute URL, query string included
     * @param array<string,string>  $headers header name => value
     * @param string|null           $body    raw request body, null for none
     * @param array<string,mixed>   $options `timeout` and `connect_timeout` (seconds, float),
     *                                       `ca_bundle` (path or null)
     *
     * @throws ConnectionException when no HTTP answer was received (DNS, TCP, TLS, timeout)
     */
    public function request(string $method, string $url, array $headers, ?string $body, array $options): ApiResponse;
}
