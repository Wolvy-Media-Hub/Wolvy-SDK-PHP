<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A webhook endpoint. `secret` (whsec_…) is only present in the answer to create: store it then.
 *
 * API object `webhook_endpoint`.
 *
 * @property-read int         $id
 * @property-read string      $object "webhook_endpoint"
 * @property-read string      $url
 * @property-read string[]    $events
 * @property-read bool        $is_active
 * @property-read int         $failure_count
 * @property-read string|null $created_at
 * @property-read string|null $updated_at
 * @property-read string|null $secret Create only.
 */
class WebhookEndpoint extends WolvyObject
{
}
