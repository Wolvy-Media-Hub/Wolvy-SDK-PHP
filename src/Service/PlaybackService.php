<?php

namespace Wolvy\Service;

use Wolvy\Model\Embed;
use Wolvy\Model\PlaybackToken;

/**
 * Signed playback through the API. To sign without an API call, use Wolvy::signViewerToken()
 * (byte-identical) and Wolvy::embedUrl().
 */
class PlaybackService extends AbstractService
{
    /**
     * POST /v1/videos/{id}/playback-tokens (scope `playback:sign`): a viewer token and the embed
     * URL, deep link and iframe that carry it. By default the token is not bound to the video:
     * it plays every video of the account until it expires. With `bind_video` true it plays only
     * this video.
     *
     * No Idempotency-Key is sent (the docs advise against one: a replay would hand back a token
     * minted earlier, and the API would store every mint for 24 hours). Minting has no side
     * effect, so the call is still retried after a network error, a 5xx or a 429. Pass
     * `idempotency_key` in $opts only if you really want a replayable mint.
     *
     * @param array<string,mixed> $params viewer_id (required, 1-64), ttl_seconds (60-86400,
     *        default 21600), bind_video (bool, default false)
     * @param array<string,mixed> $opts
     */
    public function createToken(string $videoId, array $params, array $opts = []): PlaybackToken
    {
        return self::cast(
            $this->requestWrite(
                'POST',
                '/videos/' . self::segment($videoId, 'video id') . '/playback-tokens',
                $params,
                $opts,
                ['auto_idempotency_key' => false, 'repeatable' => true]
            ),
            PlaybackToken::class
        );
    }

    /**
     * GET /v1/videos/{id}/embed (scope `videos:read`): the unsigned embed URL and an iframe.
     *
     * @param array<string,mixed> $opts
     */
    public function retrieveEmbed(string $videoId, array $opts = []): Embed
    {
        return self::cast($this->requestGet('/videos/' . self::segment($videoId, 'video id') . '/embed', [], $opts), Embed::class);
    }
}
