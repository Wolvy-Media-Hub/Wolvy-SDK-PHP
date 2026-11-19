<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\Moment;

/**
 * Moments ("highlights" in the dashboard): single points with unique timestamps, addressed by
 * `index` in the list sorted by `timestamp`.
 * Reads need `videos:read`, writes `videos:write`.
 */
class MomentsService extends AbstractService
{
    /**
     * GET /v1/videos/{id}/moments.
     *
     * @param array<string,mixed> $opts
     * @return Collection<Moment>
     */
    public function list(string $videoId, array $opts = []): Collection
    {
        return $this->requestList($this->base($videoId), [], $opts);
    }

    /**
     * POST /v1/videos/{id}/moments. Answers with the full, re-sorted list.
     *
     * @param array<string,mixed> $params label (required, 1-255), timestamp (required, seconds, unique)
     * @param array<string,mixed> $opts
     * @return Collection<Moment>
     */
    public function create(string $videoId, array $params, array $opts = []): Collection
    {
        return self::asList($this->requestWrite('POST', $this->base($videoId), $params, $opts));
    }

    /**
     * PATCH /v1/videos/{id}/moments/{index}: any of label, timestamp. Answers
     * with the full list.
     *
     * @param int|string          $index
     * @param array<string,mixed> $params
     * @param array<string,mixed> $opts
     * @return Collection<Moment>
     */
    public function update(string $videoId, $index, array $params, array $opts = []): Collection
    {
        return self::asList($this->requestWrite('PATCH', $this->base($videoId) . '/' . self::integer($index, 'moment index', 0), $params, $opts));
    }

    /**
     * DELETE /v1/videos/{id}/moments/{index}.
     *
     * @param int|string          $index
     * @param array<string,mixed> $opts
     */
    public function delete(string $videoId, $index, array $opts = []): void
    {
        $this->requestDelete($this->base($videoId) . '/' . self::integer($index, 'moment index', 0), $opts);
    }

    private function base(string $videoId): string
    {
        return '/videos/' . self::segment($videoId, 'video id') . '/moments';
    }

    private static function asList(\Wolvy\WolvyObject $object): Collection
    {
        return $object instanceof Collection ? $object : new Collection($object->toArray());
    }
}
