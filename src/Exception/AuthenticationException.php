<?php

namespace Wolvy\Exception;

/**
 * HTTP 401. No key reached the API (`missing_api_key`), or it is unknown, revoked, deactivated
 * (`invalid_api_key`) or expired (`expired_api_key`).
 */
class AuthenticationException extends ApiException
{
}
