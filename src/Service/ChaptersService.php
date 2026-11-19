<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\Chapter;

/**
 * Chapters: named, non-overlapping segments (whole seconds) on the progress bar. They are
 * addressed by `index`, the position in the list sorted by `start`, so an edit can renumber them:
 * read the list again after a change.
 * Reads need `videos:read`, writes `videos:write`.
 */
class ChaptersService extends AbstractService
{
    /**
     * GET /v1/videos/{id}/chapters.
     *
     * @param array<string,mixed> $opts
     * @return Collection<Chapter>
     */
    public function list(string $videoId, array $opts = []): Collection
    {
        return $this->requestList($this->base($videoId), [], $opts);
    }

    /**
     * POST /v1/videos/{id}/chapters. Answers with the full, re-sorted list.
     *
     * @param array<string,mixed> $params title (required, 1-255), start (required, seconds), end (required,
     *        greater than start)
     * @param array<string,mixed> $opts
     * @return Collection<Chapter>
     */
    public function create(string $videoId, array $params, array $opts = []): Collection
    {
        return self::asList($this->requestWrite('POST', $this->base($videoId), $params, $opts));
    }

    /**
     * PATCH /v1/videos/{id}/chapters/{index}: any of title, start, end. Answers
     * with the full list.
     *
     * @param int|string          $index
     * @param array<string,mixed> $params
     * @param array<string,mixed> $opts
     * @return Collection<Chapter>
     */
    public function update(string $videoId, $index, array $params, array $opts = []): Collection
    {
        return self::asList($this->requestWrite('PATCH', $this->base($videoId) . '/' . self::integer($index, 'chapter index', 0), $params, $opts));
    }

    /**
     * DELETE /v1/videos/{id}/chapters/{index}.
     *
     * @param int|string          $index
     * @param array<string,mixed> $opts
     */
    public function delete(string $videoId, $index, array $opts = []): void
    {
        $this->requestDelete($this->base($videoId) . '/' . self::integer($index, 'chapter index', 0), $opts);
    }

    private function base(string $videoId): string
    {
        return '/videos/' . self::segment($videoId, 'video id') . '/chapters';
    }

    private static function asList(\Wolvy\WolvyObject $object): Collection
    {
        return $object instanceof Collection ? $object : new Collection($object->toArray());
    }
}
