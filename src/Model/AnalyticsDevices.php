<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/analytics/devices: views and watch time by device, OS and browser.
 *
 * API object `analytics_devices`.
 *
 * @property-read string                 $object "analytics_devices"
 * @property-read string                 $from
 * @property-read string                 $to
 * @property-read string|null            $video_id
 * @property-read int                    $views
 * @property-read int                    $watch_time
 * @property-read array<int,WolvyObject> $devices [{name, views, watch_time}]
 * @property-read array<int,WolvyObject> $operating_systems [{name, views, watch_time}]
 * @property-read array<int,WolvyObject> $browsers [{name, views, watch_time}]
 */
class AnalyticsDevices extends WolvyObject
{
}
