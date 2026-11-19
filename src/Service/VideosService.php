<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\BulkResult;
use Wolvy\Model\Video;
use Wolvy\Model\VideoStatus;

/**
 * Videos. Reads need `videos:read`, writes `videos:write`.
 */
class VideosService extends AbstractService
{
    /**
     * GET /v1/videos: one page, newest first. Without `folder_id` the WHOLE library is listed.
     *
     * @param array<string,mixed> $params limit (1-100, default 50), starting_after, folder_id
     *        (an id or "root"), status (queued|processing|playable|ready|failed), q (title or
     *        description contains, or the exact id), tag, created_after, created_before
     *        (RFC 3339 strings or DateTimeInterface)
     * @param array<string,mixed> $opts
     * @return Collection<Video>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/videos', $params, $opts);
    }

    /**
     * Every video matching the filters, across all pages, fetched as you iterate.
     *
     * @param array<string,mixed> $params as list()
     * @param array<string,mixed> $opts
     * @return \Generator<int,Video>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/videos', $params, $opts);
    }

    /**
     * POST /v1/videos: upload by URL. Answers at once (HTTP 202) with `status` "queued"; Wolvy
     * downloads and encodes in the background (poll retrieveStatus() or listen for `video.ready`).
     *
     * @param array<string,mixed> $params source_url (required, public https), title, description, folder_id
     * @param array<string,mixed> $opts
     */
    public function create(array $params, array $opts = []): Video
    {
        return self::cast($this->requestWrite('POST', '/videos', $params, $opts), Video::class);
    }

    /**
     * GET /v1/videos/{id}.
     *
     * @param array<string,mixed> $opts
     */
    public function retrieve(string $id, array $opts = []): Video
    {
        return self::cast($this->requestGet('/videos/' . self::segment($id, 'video id'), [], $opts), Video::class);
    }

    /**
     * GET /v1/videos/{id}/status: the small answer to poll while a video encodes.
     *
     * @param array<string,mixed> $opts
     */
    public function retrieveStatus(string $id, array $opts = []): VideoStatus
    {
        return self::cast($this->requestGet('/videos/' . self::segment($id, 'video id') . '/status', [], $opts), VideoStatus::class);
    }

    /**
     * PATCH /v1/videos/{id}: title, description, folder_id (null = the root). At least one.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $opts
     */
    public function update(string $id, array $params, array $opts = []): Video
    {
        return self::cast($this->requestWrite('PATCH', '/videos/' . self::segment($id, 'video id'), $params, $opts), Video::class);
    }

    /**
     * DELETE /v1/videos/{id}: irreversible (media files, keys, analytics). Sends `video.deleted`.
     *
     * @param array<string,mixed> $opts
     */
    public function delete(string $id, array $opts = []): void
    {
        $this->requestDelete('/videos/' . self::segment($id, 'video id'), $opts);
    }

    /**
     * POST /v1/videos/bulk: move, tag_add, tag_remove or delete up to 50 videos. Answers with one
     * result per id; a video that cannot be changed does not stop the others.
     *
     * @param array<string,mixed> $params action (required), ids (required, 1-50), folder_id (move;
     *        null = the root), tags (tag_add, tag_remove)
     * @param array<string,mixed> $opts
     */
    public function bulk(array $params, array $opts = []): BulkResult
    {
        return self::cast($this->requestWrite('POST', '/videos/bulk', $params, $opts), BulkResult::class);
    }
}
