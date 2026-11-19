<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * One row of GET /v1/usage/history.
 *
 * API object `usage_day`.
 *
 * @property-read string $object "usage_day"
 * @property-read string $date
 * @property-read int    $storage_bytes
 * @property-read int    $bandwidth_bytes
 */
class UsageDay extends WolvyObject
{
}
