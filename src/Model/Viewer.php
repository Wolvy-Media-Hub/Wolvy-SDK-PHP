<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A viewer (a `vid` of the viewer token) over a date range. GET /v1/viewers/{viewer_id} adds from,
 * to, videos, folders, clients, countries, networks (with sessions:read) and sharing.
 *
 * API object `viewer`.
 *
 * @property-read string                      $id
 * @property-read string                      $object "viewer"
 * @property-read string                      $viewer_id
 * @property-read bool                        $verified
 * @property-read int                         $sessions
 * @property-read int                         $watch_seconds
 * @property-read int                         $bytes_streamed
 * @property-read int                         $videos_watched
 * @property-read int                         $client_count
 * @property-read int                         $network_count
 * @property-read int                         $country_count
 * @property-read string                      $first_seen_on
 * @property-read string                      $last_seen_on
 * @property-read string|null                 $last_seen_at
 * @property-read string|null                 $sharing_grade "info", "warn", "critical" or null.
 * @property-read array<int,WolvyObject>|null $videos Get a viewer only.
 * @property-read array<int,WolvyObject>|null $folders Get a viewer only.
 * @property-read array<int,WolvyObject>|null $clients Get a viewer only.
 * @property-read array<int,WolvyObject>|null $countries Get a viewer only.
 * @property-read array<int,WolvyObject>|null $networks Get a viewer only, and only with the sessions:read scope.
 * @property-read ViewerSharing|null          $sharing Get a viewer only.
 */
class Viewer extends WolvyObject
{
}
