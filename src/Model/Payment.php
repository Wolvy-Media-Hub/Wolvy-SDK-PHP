<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * The data of a `payment.paid` webhook.
 *
 * API object `payment`.
 *
 * @property-read int         $id
 * @property-read string      $object "payment"
 * @property-read float|int   $amount
 * @property-read string      $plan_type
 * @property-read string|null $billing_cycle
 */
class Payment extends WolvyObject
{
}
