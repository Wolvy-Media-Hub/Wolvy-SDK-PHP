<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A security event. In a `security.event` webhook only id, object, event_type, severity, video_id
 * and viewer_id are sent.
 *
 * API object `security_event`.
 *
 * @property-read int              $id
 * @property-read string           $object "security_event"
 * @property-read string           $event_type
 * @property-read string           $severity "info", "warn" or "critical".
 * @property-read string|null      $video_id
 * @property-read string|null      $viewer_id
 * @property-read bool|null        $viewer_verified
 * @property-read string|null      $source
 * @property-read string|null      $ip
 * @property-read string|null      $country
 * @property-read string|null      $user_agent
 * @property-read string|null      $session_id
 * @property-read WolvyObject|null $details Varies by event type.
 * @property-read string|null      $created_at
 */
class SecurityEvent extends WolvyObject
{
}
