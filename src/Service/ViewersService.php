<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Exception\InvalidArgumentException;
use Wolvy\Model\Viewer;
use Wolvy\Model\ViewerSharing;

/**
 * Viewers (grouped by the `vid` of the viewer token) and the possible-sharing report. Scope
 * `analytics:read`; a viewer's networks need `sessions:read` as well.
 */
class ViewersService extends AbstractService
{
    /**
     * GET /v1/viewers: one row per viewer over a date range, most recently seen first.
     *
     * @param array<string,mixed> $params from, to, limit, starting_after, q (id starts with),
     *        viewer_id (exact), folder_id, video_id
     * @param array<string,mixed> $opts
     * @return Collection<Viewer>
     */
    public function list(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/viewers', $params, $opts);
    }

    /**
     * Every viewer matching the filters, across all pages.
     *
     * @param array<string,mixed> $params as list()
     * @param array<string,mixed> $opts
     * @return \Generator<int,Viewer>
     */
    public function listAll(array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/viewers', $params, $opts);
    }

    /**
     * GET /v1/viewers/{viewer_id}: one viewer's totals, videos, folders, clients, countries,
     * networks (with `sessions:read`) and sharing line. An id containing "/" cannot travel in a
     * path: use list(['viewer_id' => …]) for it.
     *
     * @param array<string,mixed> $params from, to
     * @param array<string,mixed> $opts
     */
    public function retrieve(string $viewerId, array $params = [], array $opts = []): Viewer
    {
        if (strpos($viewerId, '/') !== false) {
            throw new InvalidArgumentException("A viewer id containing '/' cannot be read by path; use \$client->viewers->list(['viewer_id' => …]).");
        }
        return self::cast($this->requestGet('/viewers/' . self::segment($viewerId, 'viewer id'), $params, $opts), Viewer::class);
    }

    /**
     * GET /v1/analytics/sharing: signed viewers whose last 30 days look like a shared login,
     * highest score first. Possible sharing, never proof.
     *
     * @param array<string,mixed> $params limit, starting_after, grade (info|warn|critical)
     * @param array<string,mixed> $opts
     * @return Collection<ViewerSharing>
     */
    public function listSharing(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/analytics/sharing', $params, $opts);
    }

    /**
     * Every line of the possible-sharing report, across all pages.
     *
     * @param array<string,mixed> $params as listSharing()
     * @param array<string,mixed> $opts
     * @return \Generator<int,ViewerSharing>
     */
    public function listSharingAll(array $params = [], array $opts = []): \Generator
    {
        return $this->iterateList('/analytics/sharing', $params, $opts);
    }
}
