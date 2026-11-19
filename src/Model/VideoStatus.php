<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/videos/{id}/status: the small answer to poll while a video encodes.
 *
 * API object `video_status`.
 *
 * @property-read string      $id
 * @property-read string      $object "video_status"
 * @property-read string      $status As Video::$status.
 * @property-read float|null  $duration
 * @property-read int         $size_bytes
 * @property-read string|null $updated_at
 */
class VideoStatus extends WolvyObject
{
}
