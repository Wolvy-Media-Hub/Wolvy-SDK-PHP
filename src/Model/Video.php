<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A video. `id` is the video id that embed URLs carry.
 *
 * API object `video`.
 *
 * @property-read string                 $id
 * @property-read string                 $object "video"
 * @property-read string                 $title
 * @property-read string|null            $description
 * @property-read string                 $status "queued", "processing", "playable", "ready" or "failed" (see the STATUS_* constants).
 * @property-read string                 $protection "drm" or "clearkey" (follows the account's current plan).
 * @property-read float|null             $duration Seconds; null until ready.
 * @property-read int                    $size_bytes 0 until ready.
 * @property-read int|null               $folder_id null: the root of the library.
 * @property-read string[]               $tags
 * @property-read string                 $embed_url Unsigned embed URL; add ?vt= for signed playback.
 * @property-read string|null            $direct_play_url null when Direct Play is off.
 * @property-read string|null            $hls_url
 * @property-read string|null            $poster_url
 * @property-read string|null            $animated_poster_url
 * @property-read string|null            $original_url The source_url of an API upload.
 * @property-read array<int,WolvyObject> $captions [{label, language_code}]
 * @property-read array<int,WolvyObject> $chapters [{title, start, end}]
 * @property-read array<int,WolvyObject> $moments [{label, timestamp}]
 * @property-read string|null            $created_at RFC 3339.
 * @property-read string|null            $updated_at RFC 3339.
 */
class Video extends WolvyObject
{
    const STATUS_QUEUED = 'queued';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PLAYABLE = 'playable';
    const STATUS_READY = 'ready';
    const STATUS_FAILED = 'failed';

    /** True once every resolution is encoded. */
    public function isReady(): bool
    {
        return $this->__get('status') === self::STATUS_READY;
    }

    /** True when the video can be watched: the first resolution is done (playable) or all are (ready). */
    public function isPlayable(): bool
    {
        return in_array($this->__get('status'), [self::STATUS_PLAYABLE, self::STATUS_READY], true);
    }

    public function isFailed(): bool
    {
        return $this->__get('status') === self::STATUS_FAILED;
    }
}
