<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/analytics/overview.
 *
 * API object `analytics_overview`.
 *
 * @property-read string $object "analytics_overview"
 * @property-read string $from YYYY-MM-DD
 * @property-read string $to YYYY-MM-DD
 * @property-read int    $views
 * @property-read int    $watch_time Seconds.
 * @property-read int    $storage_bytes Peak over the range.
 * @property-read int    $bandwidth_bytes
 * @property-read int    $video_count The library now.
 */
class AnalyticsOverview extends WolvyObject
{
}
