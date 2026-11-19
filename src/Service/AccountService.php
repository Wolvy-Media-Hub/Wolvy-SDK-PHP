<?php

namespace Wolvy\Service;

use Wolvy\Model\Account;
use Wolvy\Model\PlayerSettings;

/**
 * The account the key belongs to, and its player settings. Scope `account:read`.
 */
class AccountService extends AbstractService
{
    /**
     * GET /v1/account: the account, its active plan (null without one), the calling key and its
     * limits. `id` is the account id that embed URLs carry.
     *
     * @param array<string,mixed> $opts
     */
    public function retrieve(array $opts = []): Account
    {
        return self::cast($this->requestGet('/account', [], $opts), Account::class);
    }

    /**
     * GET /v1/settings/player: theme, colours, resolutions, domain rules, subtitles, watermark
     * (with `required`: playback needs a viewer token), App-Only mode, the country rule, the viewer
     * link lock and the allowed apps. Read-only through the API.
     *
     * @param array<string,mixed> $opts
     */
    public function retrievePlayerSettings(array $opts = []): PlayerSettings
    {
        return self::cast($this->requestGet('/settings/player', [], $opts), PlayerSettings::class);
    }
}
