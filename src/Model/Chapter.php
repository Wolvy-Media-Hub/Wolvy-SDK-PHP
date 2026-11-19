<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A chapter. `index` is its position in the list sorted by start; it can change after any edit.
 *
 * API object `chapter`.
 *
 * @property-read int    $index
 * @property-read string $object "chapter"
 * @property-read string $title
 * @property-read int    $start Seconds.
 * @property-read int    $end Seconds.
 */
class Chapter extends WolvyObject
{
}
