<?php

namespace Wolvy\Service;

use Wolvy\Client;
use Wolvy\Exception\ServerException;
use Wolvy\Model\Health;

/**
 * GET /health: the API's liveness probe. Needs no API key and no scope.
 */
class HealthService extends AbstractService
{
    /**
     * GET /health. `status` is "ok", or "degraded" (database unreachable, answered with HTTP 503,
     * returned here rather than thrown). Not retried unless `max_retries` is passed.
     *
     * @param array<string,mixed> $opts
     * @throws ServerException when the API answers 5xx without a health body
     */
    public function check(array $opts = []): Health
    {
        $opts += ['max_retries' => 0];
        try {
            $response = $this->client->send('GET', $this->client->rootUrl() . '/health', [], null, $opts, ['authenticated' => false]);
        } catch (ServerException $e) {
            $json = $e->getResponse()->getJson();
            if (is_array($json) && isset($json['object']) && $json['object'] === 'health') {
                return new Health($json);
            }
            throw $e;
        }
        $object = Client::toObject($response);
        return new Health($object !== null ? $object->toArray() : []);
    }
}
