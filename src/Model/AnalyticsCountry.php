<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * One row of GET /v1/analytics/countries.
 *
 * API object `analytics_country`.
 *
 * @property-read string $object "analytics_country"
 * @property-read string $country Two-letter code.
 * @property-read int    $views
 * @property-read int    $watch_time
 */
class AnalyticsCountry extends WolvyObject
{
}
