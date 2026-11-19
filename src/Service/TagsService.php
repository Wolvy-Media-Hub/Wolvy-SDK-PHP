<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\Tag;
use Wolvy\Model\VideoTags;

/**
 * Tags on videos: 1-64 characters, no commas, at most 20 per video, compared without regard to
 * case. Reads need `videos:read`, writes `videos:write`. Filter videos by tag with
 * `$client->videos->list(['tag' => '…'])`.
 */
class TagsService extends AbstractService
{
    /**
     * GET /v1/tags: every tag of the account with its video count, most used first (up to 1,000).
     *
     * @param array<string,mixed> $opts
     * @return Collection<Tag>
     */
    public function list(array $opts = []): Collection
    {
        return $this->requestList('/tags', [], $opts);
    }

    /**
     * PUT /v1/videos/{id}/tags: replaces the whole list; [] removes every tag.
     *
     * @param string[]            $tags
     * @param array<string,mixed> $opts
     */
    public function replace(string $videoId, array $tags, array $opts = []): VideoTags
    {
        return self::cast(
            $this->requestWrite('PUT', '/videos/' . self::segment($videoId, 'video id') . '/tags', ['tags' => array_values($tags)], $opts),
            VideoTags::class
        );
    }

    /**
     * POST /v1/videos/{id}/tags: adds the tags the video does not have yet.
     *
     * @param string[]            $tags at least one
     * @param array<string,mixed> $opts
     */
    public function add(string $videoId, array $tags, array $opts = []): VideoTags
    {
        return self::cast(
            $this->requestWrite('POST', '/videos/' . self::segment($videoId, 'video id') . '/tags', ['tags' => array_values($tags)], $opts),
            VideoTags::class
        );
    }

    /**
     * DELETE /v1/videos/{id}/tags/{tag}: matched without regard to case.
     *
     * @param array<string,mixed> $opts
     */
    public function remove(string $videoId, string $tag, array $opts = []): void
    {
        $this->requestDelete('/videos/' . self::segment($videoId, 'video id') . '/tags/' . self::segment($tag, 'tag'), $opts);
    }
}
