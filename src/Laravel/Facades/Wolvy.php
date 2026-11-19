<?php

namespace Wolvy\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Wolvy\Client;

/**
 * The `Wolvy` facade (alias registered by package discovery) for the shared Wolvy\Client.
 *
 *     Wolvy::videos()->list(['status' => 'ready']);
 *     Wolvy::signViewerToken((string) auth()->id(), 3600);
 *     Wolvy::embedUrl($videoId, $vt);
 *
 * @method static \Wolvy\Service\HealthService            health()
 * @method static \Wolvy\Service\AccountService           account()
 * @method static \Wolvy\Service\VideosService            videos()
 * @method static \Wolvy\Service\FoldersService           folders()
 * @method static \Wolvy\Service\TagsService              tags()
 * @method static \Wolvy\Service\CaptionsService          captions()
 * @method static \Wolvy\Service\ChaptersService          chapters()
 * @method static \Wolvy\Service\MomentsService           moments()
 * @method static \Wolvy\Service\PlaybackService          playback()
 * @method static \Wolvy\Service\AnalyticsService         analytics()
 * @method static \Wolvy\Service\UsageService             usage()
 * @method static \Wolvy\Service\SessionsService          sessions()
 * @method static \Wolvy\Service\ViewersService           viewers()
 * @method static \Wolvy\Service\SecurityEventsService    securityEvents()
 * @method static \Wolvy\Service\WebhookEndpointsService  webhookEndpoints()
 * @method static \Wolvy\Service\WebhookDeliveriesService webhookDeliveries()
 * @method static string signViewerToken(string|int $viewerId, int $ttlSeconds = 21600)
 * @method static \Wolvy\Model\WebhookEvent verifyWebhook(string $rawBody, ?string $signatureHeader, int $tolerance = 300)
 * @method static string embedUrl(string $videoId, ?string $viewerToken = null)
 * @method static int accountId()
 * @method static bool viewerSignSecretIsCurrent(string $videoId)
 * @method static \Wolvy\ApiResponse|null getLastResponse()
 *
 * @see \Wolvy\Client
 */
class Wolvy extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Client::class;
    }
}
