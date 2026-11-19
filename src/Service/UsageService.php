<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\Plan;
use Wolvy\Model\Usage;
use Wolvy\Model\UsageDay;

/**
 * Quota and plan. Scope `usage:read`. Every quantity is bytes.
 */
class UsageService extends AbstractService
{
    /**
     * GET /v1/usage: storage and bandwidth (limit, used, available), danger_zone, the period.
     *
     * @param array<string,mixed> $opts
     */
    public function retrieve(array $opts = []): Usage
    {
        return self::cast($this->requestGet('/usage', [], $opts), Usage::class);
    }

    /**
     * GET /v1/usage/history: daily rows, oldest first.
     *
     * @param array<string,mixed> $params from, to
     * @param array<string,mixed> $opts
     * @return Collection<UsageDay>
     */
    public function history(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/usage/history', $params, $opts);
    }

    /**
     * GET /v1/plan: the active plan.
     *
     * @param array<string,mixed> $opts
     */
    public function retrievePlan(array $opts = []): Plan
    {
        return self::cast($this->requestGet('/plan', [], $opts), Plan::class);
    }
}
