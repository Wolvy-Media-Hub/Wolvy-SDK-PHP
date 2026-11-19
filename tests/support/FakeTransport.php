<?php

use Wolvy\ApiResponse;
use Wolvy\Exception\ConnectionException;
use Wolvy\Http\TransportInterface;

/**
 * A transport that answers from a queue and records every request. Queue entries are
 * ApiResponse objects, Throwables (thrown), or callables (fn (array $request): ApiResponse).
 */
final class FakeTransport implements TransportInterface
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string,options:array<string,mixed>}> */
    public $requests = [];

    /** @var array<int,mixed> */
    private $queue = [];

    /**
     * @param mixed ...$entries
     */
    public function __construct(...$entries)
    {
        $this->queue = $entries;
    }

    /** @param mixed $entry */
    public function push($entry): self
    {
        $this->queue[] = $entry;
        return $this;
    }

    public function request(string $method, string $url, array $headers, ?string $body, array $options): ApiResponse
    {
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
        $this->requests[] = $request;
        if ($this->queue === []) {
            throw new \LogicException('FakeTransport: no answer queued for ' . $method . ' ' . $url);
        }
        $entry = array_shift($this->queue);
        if ($entry instanceof \Throwable) {
            throw $entry;
        }
        if (is_callable($entry)) {
            $entry = $entry($request);
        }
        return $entry;
    }

    public function last(): array
    {
        return $this->requests[count($this->requests) - 1];
    }

    /**
     * @param array<string,mixed>|null $json
     * @param array<string,string>     $headers
     */
    public static function json(int $status, ?array $json, array $headers = []): ApiResponse
    {
        return new ApiResponse(
            $status,
            $headers + ['Content-Type' => 'application/json; charset=utf-8', 'X-Request-Id' => 'req_' . str_repeat('ab', 12)],
            $json === null ? '' : json_encode($json, JSON_UNESCAPED_SLASHES)
        );
    }

    public static function error(int $status, string $type, string $code, string $message, array $headers = [], ?string $param = null): ApiResponse
    {
        $error = ['type' => $type, 'code' => $code, 'message' => $message];
        if ($param !== null) {
            $error['param'] = $param;
        }
        $error['request_id'] = 'req_' . str_repeat('cd', 12);
        return self::json($status, ['error' => $error], $headers);
    }

    public static function down(string $message = 'Connection refused', int $errno = 7): ConnectionException
    {
        return new ConnectionException($message, $errno);
    }
}
