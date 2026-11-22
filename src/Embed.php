<?php

namespace Wolvy;

use Wolvy\Exception\InvalidArgumentException;

/**
 * Embed URLs and app deep links, built locally:
 *
 *     https://embed.wolvy.stream/{video id}/{base64(account id)}[?vt={viewer token}]
 *     wolvy://play/{video id}/{base64(account id)}/{viewer token}
 *
 * The same strings the API returns as `embed_url` and `deep_link`. A token must be signed for
 * the same video (it names it); one that names another video, or none, is refused here, as
 * Wolvy would refuse it at playback.
 */
final class Embed
{
    const DEFAULT_BASE_URL = 'https://embed.wolvy.stream';

    /**
     * @param string          $videoId     the video id (`id` of a video)
     * @param int|string      $accountId   the account id (`id` of GET /v1/account), e.g. 42
     * @param string|null     $viewerToken a viewer token signed for this video; null for an unsigned URL
     * @param string          $baseUrl     the player host
     *
     * @throws InvalidArgumentException
     */
    public static function url(string $videoId, $accountId, ?string $viewerToken = null, string $baseUrl = self::DEFAULT_BASE_URL): string
    {
        $url = rtrim($baseUrl, '/') . '/' . self::videoId($videoId) . '/' . self::account($accountId);
        if ($viewerToken !== null && $viewerToken !== '') {
            self::tokenFor($viewerToken, $videoId);
            $url .= '?vt=' . rawurlencode($viewerToken);
        }
        return $url;
    }

    /**
     * The Wolvy Player Android app link (App-Only mode). Needs a viewer token.
     *
     * @param int|string $accountId
     * @throws InvalidArgumentException
     */
    public static function deepLink(string $videoId, $accountId, string $viewerToken): string
    {
        if ($viewerToken === '') {
            throw new InvalidArgumentException('A deep link needs a viewer token.');
        }
        self::tokenFor($viewerToken, self::videoId($videoId));
        return 'wolvy://play/' . self::videoId($videoId) . '/' . self::account($accountId) . '/' . $viewerToken;
    }

    /**
     * Refuses a token that names another video, or none: Wolvy would refuse it at playback. Read
     * without the secret (the player's backend checks the signature).
     *
     * @throws InvalidArgumentException
     */
    private static function tokenFor(string $viewerToken, string $videoId): void
    {
        if (ViewerToken::decode($viewerToken) === null) {
            return; // not a token this SDK can read; Wolvy decides
        }
        $named = ViewerToken::videoOf($viewerToken);
        if ($named === null) {
            throw new InvalidArgumentException('This viewer token names no video, and Wolvy refuses such tokens. Sign it for ' . $videoId . '.');
        }
        if ($named !== $videoId) {
            throw new InvalidArgumentException('This viewer token is for video ' . $named . ', not ' . $videoId . '. Sign one token per video.');
        }
    }

    /** base64 of the account id written in decimal: 42 → "NDI=". */
    public static function encodeAccountId($accountId): string
    {
        return self::account($accountId);
    }

    private static function videoId(string $videoId): string
    {
        // The player accepts [A-Za-z0-9_-]{1,64}; anything else is a 400 page.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $videoId) !== 1) {
            throw new InvalidArgumentException('The video id must be 1 to 64 characters of A-Z, a-z, 0-9, "_" or "-".');
        }
        return $videoId;
    }

    /** @param mixed $accountId */
    private static function account($accountId): string
    {
        if (is_int($accountId) && $accountId > 0) {
            return base64_encode((string) $accountId);
        }
        if (is_string($accountId) && preg_match('/^[1-9]\d{0,18}$/', $accountId) === 1) {
            return base64_encode($accountId);
        }
        throw new InvalidArgumentException('The account id must be a positive integer (the `id` of GET /v1/account).');
    }
}
