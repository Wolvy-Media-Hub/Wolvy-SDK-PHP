<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * POST /v1/videos/bulk: one result per id, in the order sent.
 *
 * API object `bulk_result`.
 *
 * @property-read string                 $object "bulk_result"
 * @property-read string                 $action "move", "delete", "tag_add" or "tag_remove".
 * @property-read int                    $done
 * @property-read int                    $failed
 * @property-read int                    $skipped
 * @property-read array<int,WolvyObject> $results [{id, status: "done"|"failed"|"skipped", error?: {code, message}, tags?}]
 */
class BulkResult extends WolvyObject
{
}
