<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * POST /v1/videos/{id}/playback-tokens: a signed viewer token and the URLs that carry it.
 *
 * API object `playback_token`.
 *
 * @property-read string $object "playback_token"
 * @property-read string $video_id
 * @property-read string $viewer_id
 * @property-read bool $bound_to_video Always true: the token plays only this video.
 * @property-read string $vt The viewer token; byte-identical to Wolvy::signViewerToken() for the same viewer, video, lifetime and second.
 * @property-read string $expires_at RFC 3339.
 * @property-read string $embed_url The embed URL with ?vt=.
 * @property-read string $deep_link wolvy://play/{video}/{base64 account}/{vt} for the Wolvy Player app.
 * @property-read string $iframe_html A minimal iframe; docs.wolvy.net/embedding/#iframe has the recommended markup.
 */
class PlaybackToken extends WolvyObject
{
}
