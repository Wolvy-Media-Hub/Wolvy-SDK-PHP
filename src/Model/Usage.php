<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/usage. Every quantity is bytes.
 *
 * API object `usage`.
 *
 * @property-read string      $object "usage"
 * @property-read WolvyObject $storage {limit_bytes, used_bytes, available_bytes}
 * @property-read WolvyObject $bandwidth {limit_bytes, used_bytes, available_bytes}
 * @property-read bool        $danger_zone Close to the allowance.
 * @property-read WolvyObject $period {start, end}
 */
class Usage extends WolvyObject
{
}
