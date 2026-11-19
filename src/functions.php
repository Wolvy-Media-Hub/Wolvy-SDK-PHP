<?php
/**
 * The helpers as functions, loaded by Composer (`autoload.files`):
 *
 *     use function Wolvy\signViewerToken;
 *     use function Wolvy\verifyWebhook;
 *     use function Wolvy\embedUrl;
 */

namespace Wolvy;

use Wolvy\Model\WebhookEvent;

if (!function_exists('Wolvy\\signViewerToken')) {
    /**
     * A signed viewer token, byte-identical to the API's. See ViewerToken::sign().
     *
     * @param string|int $viewerId
     */
    function signViewerToken($viewerId, int $ttlSeconds, string $secret, ?int $now = null): string
    {
        return ViewerToken::sign($viewerId, $ttlSeconds, $secret, $now);
    }
}

if (!function_exists('Wolvy\\verifyWebhook')) {
    /**
     * Verifies a webhook delivery and returns its event. See Webhook::verify().
     *
     * @throws Exception\SignatureVerificationException
     */
    function verifyWebhook(
        string $rawBody,
        ?string $signatureHeader,
        string $secret,
        int $tolerance = Webhook::DEFAULT_TOLERANCE,
        ?int $now = null
    ): WebhookEvent {
        return Webhook::verify($rawBody, $signatureHeader, $secret, $tolerance, $now);
    }
}

if (!function_exists('Wolvy\\embedUrl')) {
    /**
     * The embed URL of a video, signed when a viewer token is given. See Embed::url().
     *
     * @param int|string $accountId
     */
    function embedUrl(string $videoId, $accountId, ?string $viewerToken = null, string $baseUrl = Embed::DEFAULT_BASE_URL): string
    {
        return Embed::url($videoId, $accountId, $viewerToken, $baseUrl);
    }
}
