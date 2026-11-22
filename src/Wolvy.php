<?php

namespace Wolvy;

use Wolvy\Model\WebhookEvent;

/**
 * The three helpers that need no API call, in one place. The same functions exist as
 * \Wolvy\signViewerToken(), \Wolvy\verifyWebhook() and \Wolvy\embedUrl(), and on a Client
 * configured with the secrets.
 */
final class Wolvy
{
    /** This SDK's version. */
    const VERSION = Client::VERSION;

    /**
     * A signed viewer token (`vt`) for one video, byte-identical to the API's for the same input.
     *
     * @param string|int $viewerId   trimmed, then 1 to 64 bytes
     * @param int        $ttlSeconds 60 to 86400
     * @param string     $secret     the account's viewer signing secret
     * @param string     $video      the Wolvy video id the token plays (required)
     * @param int|null   $now        Unix time to sign at (tests)
     */
    public static function signViewerToken($viewerId, int $ttlSeconds, string $secret, string $video, ?int $now = null): string
    {
        return ViewerToken::sign($viewerId, $ttlSeconds, $secret, $video, $now);
    }

    /**
     * Verifies a webhook delivery and returns its event; throws SignatureVerificationException.
     *
     * @param int $tolerance seconds; default 300 (5 minutes), 0 turns the time check off
     */
    public static function verifyWebhook(
        string $rawBody,
        ?string $signatureHeader,
        string $secret,
        int $tolerance = Webhook::DEFAULT_TOLERANCE,
        ?int $now = null
    ): WebhookEvent {
        return Webhook::verify($rawBody, $signatureHeader, $secret, $tolerance, $now);
    }

    /**
     * The embed URL, with `?vt=` when a token is given.
     *
     * @param int|string $accountId
     */
    public static function embedUrl(string $videoId, $accountId, ?string $viewerToken = null, string $baseUrl = Embed::DEFAULT_BASE_URL): string
    {
        return Embed::url($videoId, $accountId, $viewerToken, $baseUrl);
    }

    /**
     * The Wolvy Player app link for a video and a viewer token.
     *
     * @param int|string $accountId
     */
    public static function deepLink(string $videoId, $accountId, string $viewerToken): string
    {
        return Embed::deepLink($videoId, $accountId, $viewerToken);
    }
}
