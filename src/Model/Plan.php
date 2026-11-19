<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/plan.
 *
 * API object `plan`.
 *
 * @property-read string      $object "plan"
 * @property-read string|null $name
 * @property-read string      $type "fixed" or "custom".
 * @property-read string      $protection "drm" or "clearkey".
 * @property-read int         $storage_limit_bytes
 * @property-read int         $bandwidth_limit_bytes
 * @property-read string|null $start_date
 * @property-read string|null $end_date
 */
class Plan extends WolvyObject
{
}
