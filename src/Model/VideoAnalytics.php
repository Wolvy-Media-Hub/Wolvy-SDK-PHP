<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/videos/{id}/analytics: lifetime totals of one video.
 *
 * API object `video_analytics`.
 *
 * @property-read string      $object "video_analytics"
 * @property-read string      $video_id
 * @property-read int         $views
 * @property-read int         $sessions
 * @property-read int         $watch_time
 * @property-read float|null  $engagement_score
 * @property-read WolvyObject $countries {CC: views}
 */
class VideoAnalytics extends WolvyObject
{
}
