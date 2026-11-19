<?php

namespace Wolvy\Service;

use Wolvy\Client;
use Wolvy\Collection;
use Wolvy\Exception\InvalidArgumentException;
use Wolvy\WolvyObject;

/**
 * Base of the per-resource services. Every method ends with an optional `$opts` array of
 * per-call options:
 *
 * - `idempotency_key` (POST only): a string to use instead of the generated one, true to have
 *   one generated, or false to send none (then a POST is not retried after a network error or
 *   a 5xx). playback->createToken() sends none unless one is passed, and is retried anyway;
 * - `timeout`, `connect_timeout` (seconds), `max_retries` (int): override the client's;
 * - `headers`: extra request headers.
 */
abstract class AbstractService
{
    /** @var Client */
    protected $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $opts
     */
    protected function requestGet(string $path, array $query = [], array $opts = []): WolvyObject
    {
        $object = Client::toObject($this->client->send('GET', $this->client->url($path), $query, null, $opts));
        return $object !== null ? $object : new WolvyObject([]);
    }

    /**
     * A list page whose nextPage() asks for the following page with the same filters.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $opts
     */
    protected function requestList(string $path, array $query = [], array $opts = []): Collection
    {
        $object = $this->requestGet($path, $query, $opts);
        $list = $object instanceof Collection ? $object : new Collection($object->toArray());
        return $list->setNextPageFetcher(function (string $cursor) use ($path, $query, $opts): Collection {
            return $this->requestList($path, array_merge($query, ['starting_after' => $cursor]), $opts);
        });
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $opts
     * @return \Generator<int,WolvyObject>
     */
    protected function iterateList(string $path, array $query = [], array $opts = []): \Generator
    {
        // A generator: nothing is sent until the first item is asked for.
        yield from $this->requestList($path, $query, $opts)->autoPagingIterator();
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $opts
     * @param array<string,bool>  $flags see Client::send()
     */
    protected function requestWrite(string $method, string $path, array $body = [], array $opts = [], array $flags = []): WolvyObject
    {
        $object = Client::toObject($this->client->send($method, $this->client->url($path), [], $body, $opts, $flags));
        return $object !== null ? $object : new WolvyObject([]);
    }

    /**
     * @param array<string,mixed> $opts
     */
    protected function requestDelete(string $path, array $opts = []): void
    {
        $this->client->send('DELETE', $this->client->url($path), [], null, $opts);
    }

    /**
     * The object as $class: the API's `object` field already chose it; this only guarantees the
     * declared return types should a field ever be missing.
     *
     * @template T of WolvyObject
     * @param class-string<T> $class
     * @return T
     */
    protected static function cast(WolvyObject $object, string $class): WolvyObject
    {
        return $object instanceof $class ? $object : new $class($object->toArray());
    }

    /**
     * A string path segment (video id, tag, language, session id), URL-encoded.
     *
     * @param mixed $value
     */
    protected static function segment($value, string $what): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("The {$what} must be a non-empty string.");
        }
        if ($value === '.' || $value === '..') {
            throw new InvalidArgumentException("The {$what} cannot be '{$value}'.");
        }
        return rawurlencode($value);
    }

    /**
     * An integer path segment (folder id, endpoint id, chapter or moment index).
     *
     * @param mixed $value
     */
    protected static function integer($value, string $what, int $min = 1): string
    {
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min) {
            throw new InvalidArgumentException("The {$what} must be an integer of at least {$min}.");
        }
        return (string) $value;
    }
}
