<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A line of the possible-sharing report. A signal, never proof.
 *
 * API object `viewer_sharing`.
 *
 * @property-read string                 $object "viewer_sharing"
 * @property-read string                 $viewer_id
 * @property-read string                 $grade "info", "warn" or "critical".
 * @property-read int                    $score
 * @property-read array<int,WolvyObject> $reasons [{signal, value, level, threshold, ...}]
 * @property-read string                 $window_from
 * @property-read string                 $window_to
 * @property-read string|null            $computed_at
 */
class ViewerSharing extends WolvyObject
{
}
