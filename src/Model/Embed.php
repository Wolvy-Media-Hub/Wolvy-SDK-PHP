<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/videos/{id}/embed: the unsigned embed URL.
 *
 * API object `embed`.
 *
 * @property-read string      $object "embed"
 * @property-read string      $video_id
 * @property-read string      $embed_url
 * @property-read string      $iframe_html
 * @property-read string|null $direct_play_url
 * @property-read string      $note
 */
class Embed extends WolvyObject
{
}
