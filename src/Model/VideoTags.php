<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A video's tags after PUT or POST /v1/videos/{id}/tags.
 *
 * API object `video_tags`.
 *
 * @property-read string   $object "video_tags"
 * @property-read string   $video_id
 * @property-read string[] $tags
 */
class VideoTags extends WolvyObject
{
}
