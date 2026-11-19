<?php

namespace Wolvy\Http;

use Wolvy\ApiResponse;
use Wolvy\Exception\ConnectionException;

/**
 * The default transport, on ext-curl. One handle per transport, reset between requests, so
 * consecutive calls (paging through a list) reuse the connection.
 */
final class CurlTransport implements TransportInterface
{
    /** @var resource|\CurlHandle|null */
    private $handle;

    public function request(string $method, string $url, array $headers, ?string $body, array $options): ApiResponse
    {
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new ConnectionException('Could not initialise cURL.');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }
        $ch = $this->handle;

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        // Never wait for a "100 Continue" the API does not send.
        $headerLines[] = 'Expect:';

        $responseHeaders = [];
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : 80.0;
        $connectTimeout = isset($options['connect_timeout']) ? (float) $options['connect_timeout'] : 10.0;

        $curlOptions = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT_MS     => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connectTimeout * 1000),
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if (stripos($trimmed, 'HTTP/') === 0) {
                    $responseHeaders = []; // a new status line (after 100 Continue or a proxy CONNECT)
                } elseif ($trimmed !== '' && strpos($trimmed, ':') !== false) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name] . ', ' . $value : $value;
                }
                return strlen($line);
            },
        ];
        if ($method === 'HEAD') {
            $curlOptions[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $curlOptions[CURLOPT_POSTFIELDS] = ''; // sends Content-Length: 0
        }
        if (!empty($options['ca_bundle'])) {
            $curlOptions[CURLOPT_CAINFO] = (string) $options['ca_bundle'];
        }
        curl_setopt_array($ch, $curlOptions);

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $this->close();
            throw new ConnectionException(
                sprintf('Could not reach the Wolvy API (%s %s): %s [cURL %d]', $method, self::redact($url), $error, $errno),
                $errno
            );
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return new ApiResponse($status, $responseHeaders, (string) $responseBody);
    }

    /** Drops the handle; the next request opens a new connection. */
    public function close(): void
    {
        if ($this->handle !== null && PHP_VERSION_ID < 80000) {
            curl_close($this->handle); // a no-op (and deprecated in 8.5) since PHP 8.0
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** The URL without its query string, for messages (a query can carry viewer ids). */
    private static function redact(string $url): string
    {
        $q = strpos($url, '?');
        return $q === false ? $url : substr($url, 0, $q) . '?…';
    }
}
