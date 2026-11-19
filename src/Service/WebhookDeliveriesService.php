<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\WebhookDelivery;

/**
 * Webhook deliveries: what was sent, the status your server answered, the next retry.
 * Scope `webhooks:manage`.
 */
class WebhookDeliveriesService extends AbstractService
{
    /**
     * GET /v1/webhook-deliveries: one page, newest first.
     *
     * @param array<string,mixed> $params limit, starting_after, endpoint_id, status (pending|delivered|failed)
     * @param array<string,mixed> $opts
     * @return Collection<WebhookDelivery>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/webhook-deliveries', $params, $opts);
    }

    /**
     * Every delivery matching the filters, across all pages.
     *
     * @param array<string,mixed> $params as list()
     * @param array<string,mixed> $opts
     * @return \Generator<int,WebhookDelivery>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/webhook-deliveries', $params, $opts);
    }
}
