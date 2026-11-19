<?php

namespace Wolvy\Service;

use Wolvy\Collection;
use Wolvy\Model\AnalyticsCountry;
use Wolvy\Model\AnalyticsDay;
use Wolvy\Model\AnalyticsDevices;
use Wolvy\Model\AnalyticsOverview;
use Wolvy\Model\VideoAnalytics;

/**
 * Views, watch time (seconds) and delivery (bytes). Scope `analytics:read`. Ranges are `from`
 * and `to` (YYYY-MM-DD strings or DateTimeInterface), the last 30 days by default.
 */
class AnalyticsService extends AbstractService
{
    /**
     * GET /v1/analytics/overview.
     *
     * @param array<string,mixed> $params from, to
     * @param array<string,mixed> $opts
     */
    public function overview(array $params = [], array $opts = []): AnalyticsOverview
    {
        return self::cast($this->requestGet('/analytics/overview', $params, $opts), AnalyticsOverview::class);
    }

    /**
     * GET /v1/analytics/daily: one row per day, oldest first.
     *
     * @param array<string,mixed> $params from, to
     * @param array<string,mixed> $opts
     * @return Collection<AnalyticsDay>
     */
    public function daily(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/analytics/daily', $params, $opts);
    }

    /**
     * GET /v1/analytics/countries: by views, highest first. With `video_id`, that video's
     * lifetime figures (the range is ignored).
     *
     * @param array<string,mixed> $params from, to, video_id
     * @param array<string,mixed> $opts
     * @return Collection<AnalyticsCountry>
     */
    public function countries(array $params = [], array $opts = []): Collection
    {
        return $this->requestList('/analytics/countries', $params, $opts);
    }

    /**
     * GET /v1/analytics/devices: views and watch time by device, operating system and browser.
     *
     * @param array<string,mixed> $params from, to, video_id
     * @param array<string,mixed> $opts
     */
    public function devices(array $params = [], array $opts = []): AnalyticsDevices
    {
        return self::cast($this->requestGet('/analytics/devices', $params, $opts), AnalyticsDevices::class);
    }

    /**
     * GET /v1/videos/{id}/analytics: lifetime totals of one video.
     *
     * @param array<string,mixed> $opts
     */
    public function video(string $videoId, array $opts = []): VideoAnalytics
    {
        return self::cast($this->requestGet('/videos/' . self::segment($videoId, 'video id') . '/analytics', [], $opts), VideoAnalytics::class);
    }
}
