<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\Caption;

/**
 * WebVTT caption tracks of a video, one per language code. Reads need `videos:read`, writes
 * `videos:write`.
 */
class CaptionsService extends AbstractService
{
    /**
     * GET /v1/videos/{id}/captions.
     *
     * @param array<string,mixed> $opts
     * @return Collection<Caption>
     */
    public function list(string $videoId, array $opts = []): Collection
    {
        return $this->requestList('/videos/' . self::segment($videoId, 'video id') . '/captions', [], $opts);
    }

    /**
     * POST /v1/videos/{id}/captions: adds or replaces the track of a language. Send exactly one of
     * `content` (the WebVTT text) or `source_url`. Answers with the full list.
     *
     * @param array<string,mixed> $params language_code (required, e.g. "en", "pt-BR"), label,
     *        content, source_url
     * @param array<string,mixed> $opts
     * @return Collection<Caption>
     */
    public function create(string $videoId, array $params, array $opts = []): Collection
    {
        $list = $this->requestWrite('POST', '/videos/' . self::segment($videoId, 'video id') . '/captions', $params, $opts);
        return $list instanceof Collection ? $list : new Collection($list->toArray());
    }

    /**
     * DELETE /v1/videos/{id}/captions/{language}.
     *
     * @param array<string,mixed> $opts
     */
    public function delete(string $videoId, string $languageCode, array $opts = []): void
    {
        $this->requestDelete(
            '/videos/' . self::segment($videoId, 'video id') . '/captions/' . self::segment($languageCode, 'language code'),
            $opts
        );
    }
}
