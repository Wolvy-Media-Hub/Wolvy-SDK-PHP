<?php

namespace Wolvy;

/**
 * A list answer: `{"object": "list", "data": [...], "has_more": bool, "next_cursor": string|null}`.
 *
 * Iterating a Collection walks the items of THIS page. To walk every page, newest first, use
 * autoPagingIterator() (or the `listAll()` / `each…()` methods of the services), which fetches the
 * next page with `starting_after = next_cursor` and the same filters, only when it is needed:
 *
 *     foreach ($client->videos->listAll(['status' => 'ready']) as $video) { ... }
 *
 * Lists that are not paginated (captions, chapters, tags, endpoints, analytics rows) come back
 * whole, with `has_more` false.
 *
 * @property-read array<int,WolvyObject> $data   the items of this page
 * @property-read bool                   $has_more
 * @property-read string|null            $next_cursor
 */
class Collection extends WolvyObject
{
    /** @var callable|null function (string $cursor): Collection */
    private $fetchNext;

    /** @return array<int,WolvyObject> the items of this page */
    public function getData(): array
    {
        $data = $this->__get('data');
        return is_array($data) ? $data : [];
    }

    public function hasMore(): bool
    {
        return $this->__get('has_more') === true && $this->getNextCursor() !== null;
    }

    public function getNextCursor(): ?string
    {
        $cursor = $this->__get('next_cursor');
        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function isEmpty(): bool
    {
        return $this->getData() === [];
    }

    /** @return WolvyObject|null the first item of this page */
    public function first()
    {
        $data = $this->getData();
        return $data === [] ? null : $data[0];
    }

    /**
     * The next page, or null when this is the last one.
     */
    public function nextPage(): ?self
    {
        if (!$this->hasMore() || $this->fetchNext === null) {
            return null;
        }
        return call_user_func($this->fetchNext, $this->getNextCursor());
    }

    /**
     * Every item of this page and of the pages after it, fetched lazily.
     *
     * @return \Generator<int,WolvyObject>
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        while ($page !== null) {
            foreach ($page->getData() as $item) {
                yield $item;
            }
            $page = $page->nextPage();
        }
    }

    /** Iterates the items of this page. */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->getData());
    }

    /** The number of items on this page. */
    public function count(): int
    {
        return count($this->getData());
    }

    /**
     * @internal set by the service that fetched the page
     * @param callable $fetchNext function (string $cursor): Collection
     */
    public function setNextPageFetcher(callable $fetchNext): self
    {
        $this->fetchNext = $fetchNext;
        return $this;
    }
}
