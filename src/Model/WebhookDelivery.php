<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A webhook delivery (POST /v1/webhook-endpoints/{id}/test answers a pending one).
 *
 * API object `webhook_delivery`.
 *
 * @property-read int         $id Matches the Wolvy-Delivery-Id header.
 * @property-read string      $object "webhook_delivery"
 * @property-read int         $endpoint_id
 * @property-read string      $event_type
 * @property-read int|null    $attempt
 * @property-read string      $status "pending", "delivered" or "failed".
 * @property-read int|null    $response_code
 * @property-read string|null $next_retry_at
 * @property-read string|null $created_at
 * @property-read string|null $delivered_at
 */
class WebhookDelivery extends WolvyObject
{
}
