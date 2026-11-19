<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/account: the account the key belongs to, its plan and the key itself. `id` is the
 * account id that embed URLs carry (base64).
 *
 * API object `account`.
 *
 * @property-read int              $id The account id.
 * @property-read string           $object "account"
 * @property-read string           $name
 * @property-read string           $username
 * @property-read string           $email
 * @property-read string           $status "active" (an inactive account cannot use the API).
 * @property-read string|null      $created_at RFC 3339.
 * @property-read WolvyObject|null $plan {name, type, protection ("drm"|"clearkey"), end_date}; null without an active plan.
 * @property-read WolvyObject      $api_key {id, name, prefix, last_four, scopes[], created_at}: the key making the call.
 * @property-read WolvyObject      $rate_limits {per_minute, per_day, requests_today}.
 */
class Account extends WolvyObject
{
}
