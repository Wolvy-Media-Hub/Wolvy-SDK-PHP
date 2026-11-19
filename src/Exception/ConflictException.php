<?php

namespace Wolvy\Exception;

/**
 * HTTP 409. `idempotency_key_reused` (same key, different request), `request_in_progress` (the
 * first request with this key is still running; the SDK waits and retries it), `chapter_overlap`,
 * `moment_exists`, `folder_loop`, `folder_too_deep`, `not_available`.
 */
class ConflictException extends ApiException
{
}
