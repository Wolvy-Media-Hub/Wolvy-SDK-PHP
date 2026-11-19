<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\WebhookDelivery;
use Wolvy\Model\WebhookEndpoint;

/**
 * Webhook endpoints. Scope `webhooks:manage`. Verify deliveries with Wolvy::verifyWebhook().
 */
class WebhookEndpointsService extends AbstractService
{
    /**
     * GET /v1/webhook-endpoints: every endpoint, without secrets. Not paginated.
     *
     * @param array<string,mixed> $opts
     * @return Collection<WebhookEndpoint>
     */
    public function list(array $opts = []): Collection
    {
        return $this->requestList('/webhook-endpoints', [], $opts);
    }

    /**
     * POST /v1/webhook-endpoints. The answer carries the signing `secret` (whsec_…) this once:
     * store it before anything else.
     *
     * @param array<string,mixed> $params url (required: public https, port 443 or 8443),
     *        events (required: see Model\WebhookEvent::SUBSCRIBABLE)
     * @param array<string,mixed> $opts
     */
    public function create(array $params, array $opts = []): WebhookEndpoint
    {
        return self::cast($this->requestWrite('POST', '/webhook-endpoints', $params, $opts), WebhookEndpoint::class);
    }

    /**
     * GET /v1/webhook-endpoints/{id}.
     *
     * @param int|string          $id
     * @param array<string,mixed> $opts
     */
    public function retrieve($id, array $opts = []): WebhookEndpoint
    {
        return self::cast($this->requestGet('/webhook-endpoints/' . self::integer($id, 'endpoint id'), [], $opts), WebhookEndpoint::class);
    }

    /**
     * PATCH /v1/webhook-endpoints/{id}: url, events (replaces the list), is_active (true also
     * resets failure_count and resumes pending deliveries).
     *
     * @param int|string          $id
     * @param array<string,mixed> $params
     * @param array<string,mixed> $opts
     */
    public function update($id, array $params, array $opts = []): WebhookEndpoint
    {
        return self::cast($this->requestWrite('PATCH', '/webhook-endpoints/' . self::integer($id, 'endpoint id'), $params, $opts), WebhookEndpoint::class);
    }

    /**
     * DELETE /v1/webhook-endpoints/{id}, with its delivery history.
     *
     * @param int|string          $id
     * @param array<string,mixed> $opts
     */
    public function delete($id, array $opts = []): void
    {
        $this->requestDelete('/webhook-endpoints/' . self::integer($id, 'endpoint id'), $opts);
    }

    /**
     * POST /v1/webhook-endpoints/{id}/test: queues a `webhook.test` delivery (sent within a
     * minute or two) and answers with it, `status` "pending".
     *
     * @param int|string          $id
     * @param array<string,mixed> $opts
     */
    public function sendTest($id, array $opts = []): WebhookDelivery
    {
        return self::cast($this->requestWrite('POST', '/webhook-endpoints/' . self::integer($id, 'endpoint id') . '/test', [], $opts), WebhookDelivery::class);
    }
}
