<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A moment (a "highlight" in the dashboard). `index` is its position, sorted by timestamp.
 *
 * API object `moment`.
 *
 * @property-read int    $index
 * @property-read string $object "moment"
 * @property-read string $label
 * @property-read int    $timestamp Seconds.
 */
class Moment extends WolvyObject
{
}
