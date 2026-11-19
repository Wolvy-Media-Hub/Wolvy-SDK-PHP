<?php

namespace Wolvy\Exception;

/**
 * HTTP 403. The key lacks the scope this call needs (`insufficient_scope`), is limited to other IP
 * addresses (`ip_not_allowed`), or the account is not active (`account_inactive`).
 */
class PermissionException extends ApiException
{
}
