<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\ViewerSession;

/**
 * Playback sessions. Scope `sessions:read`. They include viewers' IP addresses and user agents:
 * personal data you are the controller of.
 */
class SessionsService extends AbstractService
{
    /**
     * GET /v1/sessions: one page, newest first; only sessions that played (`watch_seconds` > 0).
     *
     * @param array<string,mixed> $params limit, starting_after, video_id, viewer_id, country,
     *        created_after, created_before
     * @param array<string,mixed> $opts
     * @return Collection<ViewerSession>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/sessions', $params, $opts);
    }

    /**
     * Every session matching the filters, across all pages.
     *
     * @param array<string,mixed> $params as list()
     * @param array<string,mixed> $opts
     * @return \Generator<int,ViewerSession>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/sessions', $params, $opts);
    }

    /**
     * GET /v1/sessions/{session_id}: any session of the account, played or not.
     *
     * @param array<string,mixed> $opts
     */
    public function retrieve(string $sessionId, array $opts = []): ViewerSession
    {
        return self::cast($this->requestGet('/sessions/' . self::segment($sessionId, 'session id'), [], $opts), ViewerSession::class);
    }

    /**
     * GET /v1/videos/{id}/sessions: list() for one video.
     *
     * @param array<string,mixed> $params limit, starting_after, viewer_id, country, created_after, created_before
     * @param array<string,mixed> $opts
     * @return Collection<ViewerSession>
     */
    public function listForVideo(string $videoId, array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/videos/' . self::segment($videoId, 'video id') . '/sessions', $params, $opts);
    }

    /**
     * Every session of one video, across all pages.
     *
     * @param array<string,mixed> $params as listForVideo()
     * @param array<string,mixed> $opts
     * @return \Generator<int,ViewerSession>
     */
    public function listAllForVideo(string $videoId, array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/videos/' . self::segment($videoId, 'video id') . '/sessions', $params, $opts);
    }
}
