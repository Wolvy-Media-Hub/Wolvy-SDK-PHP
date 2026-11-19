<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\SecurityEvent;

/**
 * Security events: the audit trail of suspicious and refused playback. Scope `security:read`.
 */
class SecurityEventsService extends AbstractService
{
    /**
     * GET /v1/security-events: one page, newest first.
     *
     * @param array<string,mixed> $params limit, starting_after, event_type, severity
     *        (info|warn|critical), video_id, viewer_id, created_after, created_before
     * @param array<string,mixed> $opts
     * @return Collection<SecurityEvent>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/security-events', $params, $opts);
    }

    /**
     * Every event matching the filters, across all pages.
     *
     * @param array<string,mixed> $params as list()
     * @param array<string,mixed> $opts
     * @return \Generator<int,SecurityEvent>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/security-events', $params, $opts);
    }
}
