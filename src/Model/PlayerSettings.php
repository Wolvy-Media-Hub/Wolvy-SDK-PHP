<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * GET /v1/settings/player: the account-wide player setup, read-only through the API.
 *
 * API object `player_settings`.
 *
 * @property-read string                 $object "player_settings"
 * @property-read string|null            $theme
 * @property-read WolvyObject            $colors {player, accent, background}.
 * @property-read string[]               $available_resolutions
 * @property-read string[]               $allowed_domains Empty: any site that sends a Referer may embed.
 * @property-read string[]               $blocked_domains
 * @property-read WolvyObject            $subtitles {font, color, background, size}.
 * @property-read WolvyObject            $watermark {enabled, mode, position, opacity, required}. With enabled and required true, playback needs a valid viewer token.
 * @property-read bool                   $app_only_l3 App-Only mode.
 * @property-read bool                   $keep_original
 * @property-read WolvyObject            $geo {mode: "off"|"allow"|"block", countries[], except_ips[]}: the country rule.
 * @property-read bool                   $viewer_link_lock A viewer token plays only on the network it was first opened on.
 * @property-read array<int,WolvyObject> $allowed_apps [{platform: "android"|"ios", id}]: apps that may play through the mobile SDKs.
 */
class PlayerSettings extends WolvyObject
{
}
