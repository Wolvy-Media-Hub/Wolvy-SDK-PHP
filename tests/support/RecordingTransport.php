<?php

use Wolvy\ApiResponse;
use Wolvy\Exception\ConnectionException;
use Wolvy\Http\CurlTransport;
use Wolvy\Http\TransportInterface;

/**
 * The real cURL transport, with every request and answer recorded. With $loseAnswers = n, the
 * first n answers are thrown away AFTER the API has handled the request, and a
 * ConnectionException is thrown instead: the case Idempotency-Key exists for (the work was done,
 * the client never heard).
 */
final class RecordingTransport implements TransportInterface
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string,status:int|null,response:?ApiResponse}> */
    public $log = [];

    /** @var int */
    private $loseAnswers;

    /** @var CurlTransport */
    private $inner;

    public function __construct(int $loseAnswers = 0)
    {
        $this->inner = new CurlTransport();
        $this->loseAnswers = $loseAnswers;
    }

    public function request(string $method, string $url, array $headers, ?string $body, array $options): ApiResponse
    {
        $entry = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'status' => null, 'response' => null];
        try {
            $response = $this->inner->request($method, $url, $headers, $body, $options);
        } catch (ConnectionException $e) {
            $this->log[] = $entry;
            throw $e;
        }
        $entry['status'] = $response->getStatus();
        $entry['response'] = $response;
        $this->log[] = $entry;
        if ($this->loseAnswers > 0) {
            $this->loseAnswers--;
            throw new ConnectionException('Simulated: the answer (HTTP ' . $response->getStatus() . ') was lost on the way back.', 56);
        }
        return $response;
    }

    public function count(): int
    {
        return count($this->log);
    }

    public function last(): array
    {
        return $this->log[count($this->log) - 1];
    }
}
