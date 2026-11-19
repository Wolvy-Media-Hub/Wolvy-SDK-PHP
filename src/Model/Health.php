<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /health (no key): `status` "ok", or "degraded" with `database` "unreachable".
 *
 * API object `health`.
 *
 * @property-read string $object "health"
 * @property-read string $status "ok" or "degraded".
 * @property-read string $database "ok" or "unreachable".
 */
class Health extends WolvyObject
{
}
