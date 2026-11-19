<?php

namespace Wolvy\Exception;

/**
 * HTTP 404. The resource does not exist or belongs to another account (the API never tells the two
 * apart): `video_not_found`, `folder_not_found`, `webhook_endpoint_not_found`, `not_found`,
 * `unknown_endpoint`...
 */
class NotFoundException extends ApiException
{
}
