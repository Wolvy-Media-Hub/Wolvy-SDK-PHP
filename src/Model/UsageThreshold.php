<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * The data of a `usage.threshold_reached` webhook: 80 % or 100 % of the plan's storage or
 * bandwidth, once per billing period.
 *
 * API object `usage_threshold`.
 *
 * @property-read string      $object "usage_threshold"
 * @property-read string      $metric "storage" or "bandwidth".
 * @property-read int         $threshold_percent 80 or 100.
 * @property-read float       $used_percent
 * @property-read int         $used_bytes
 * @property-read int         $limit_bytes
 * @property-read int         $available_bytes
 * @property-read WolvyObject $period {start, end}
 */
class UsageThreshold extends WolvyObject
{
}
