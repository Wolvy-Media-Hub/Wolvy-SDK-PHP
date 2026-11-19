<?php

namespace Wolvy\Exception;

/**
 * HTTP 400 or 405. The request itself is wrong: a missing, unknown or invalid parameter
 * (`missing_parameter`, `unknown_parameter`, `invalid_parameter`, `invalid_cursor`,
 * `no_fields`...), or a method the path does not take (`method_not_allowed`). Fix the request;
 * retrying it unchanged fails again.
 */
class InvalidRequestException extends ApiException
{
}
