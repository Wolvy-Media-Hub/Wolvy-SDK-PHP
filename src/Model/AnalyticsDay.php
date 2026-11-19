<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * One row of GET /v1/analytics/daily.
 *
 * API object `analytics_day`.
 *
 * @property-read string $object "analytics_day"
 * @property-read string $date YYYY-MM-DD
 * @property-read int    $views
 * @property-read int    $watch_time
 * @property-read int    $storage_bytes
 * @property-read int    $bandwidth_bytes
 */
class AnalyticsDay extends WolvyObject
{
}
