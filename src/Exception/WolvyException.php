<?php

namespace Wolvy\Exception;

/**
 * Implemented by every exception this SDK throws, so one catch block covers all of them:
 *
 *     try { ... } catch (\Wolvy\Exception\WolvyException $e) { ... }
 */
interface WolvyException extends \Throwable
{
}
