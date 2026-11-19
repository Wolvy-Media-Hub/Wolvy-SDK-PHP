<?php

namespace Wolvy\Exception;

/**
 * A value passed to the SDK is not usable (an empty secret, a TTL out of range, a malformed
 * video id...). Thrown before anything is sent, so nothing was changed.
 */
class InvalidArgumentException extends \InvalidArgumentException implements WolvyException
{
}
