<?php

namespace Wolvy\Exception;

/**
 * HTTP 422. Well-formed but refused: `no_active_plan`, `insufficient_storage`,
 * `account_settings_missing`, `too_many_tags`, the source-URL codes (`invalid_source_url`,
 * `source_not_allowed`, `source_unreachable`, `unsupported_source_type`, `source_too_large`,
 * `too_many_redirects`) and `invalid_caption_file`.
 */
class UnprocessableEntityException extends ApiException
{
}
