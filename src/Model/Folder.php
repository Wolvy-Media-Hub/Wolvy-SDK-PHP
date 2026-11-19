<?php

namespace Wolvy\Model;

use Wolvy\WolvyObject;

/**
 * A folder. Folders nest up to 5 levels.
 *
 * API object `folder`.
 *
 * @property-read int                    $id
 * @property-read string                 $object "folder"
 * @property-read string                 $name
 * @property-read int|null               $parent_id null at the top level.
 * @property-read array<int,WolvyObject> $path [{id, name}] from the top level down to this folder.
 * @property-read int                    $video_count Videos directly in this folder.
 * @property-read int                    $folder_count Sub-folders directly in it.
 * @property-read string|null            $created_at
 */
class Folder extends WolvyObject
{
}
