<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A playback session. Contains the viewer's IP address and user agent: personal data.
 *
 * API object `viewer_session`.
 *
 * @property-read string      $id The session id.
 * @property-read string      $object "viewer_session"
 * @property-read string      $video_id
 * @property-read string|null $viewer_id
 * @property-read bool|null   $viewer_verified true when the id came from a signed viewer token.
 * @property-read int         $watch_seconds
 * @property-read float|null  $watch_percent 0 to 100.
 * @property-read int         $bytes_streamed
 * @property-read string|null $country
 * @property-read string|null $ip
 * @property-read string|null $user_agent
 * @property-read string|null $created_at
 * @property-read string|null $updated_at
 */
class ViewerSession extends WolvyObject
{
}
