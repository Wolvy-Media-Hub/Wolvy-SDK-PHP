<?php

namespace Wolvy;

use Wolvy\Exception\InvalidArgumentException;

/**
 * Signed viewer tokens (`vt`), made locally with the account's viewer signing secret
 * (dashboard: Settings → Watermark → Signing secret; never send it to a browser or an app).
 *
 *     payload = base64url( json_encode({"vid": <viewer id>, "exp": <unix seconds>, "video": <video id>}) )
 *     sig     = base64url( HMAC-SHA256(secret, payload) )      // over the ENCODED payload
 *     vt      = payload . "." . sig
 *
 * Every token names the one video it plays: Wolvy refuses a token without a "video" claim
 * (since 2026-11-22), so a token copied out of one page cannot open the account's other videos.
 * Sign one token per video.
 *
 * sign() is byte-identical to `POST /v1/videos/{id}/playback-tokens` for the same viewer id,
 * video, lifetime and second: the same input checks (the id is trimmed, then 1 to 64 bytes; 60 to
 * 86,400 seconds) and PHP's default json_encode(), which escapes "/" and non-ASCII characters
 * exactly as the API does. A token made by other code that spells the JSON differently still
 * verifies; it just is not the same string.
 */
final class ViewerToken
{
    /** Shortest lifetime the API issues, in seconds. */
    const MIN_TTL = 60;

    /** Longest lifetime the API issues (24 hours). */
    const MAX_TTL = 86400;

    /** The API's default lifetime (6 hours). */
    const DEFAULT_TTL = 21600;

    /** How far ahead an `exp` may be and still verify at playback (30 days). */
    const VERIFY_MAX_TTL = 2592000;

    /** Longest viewer id, in bytes. */
    const MAX_VIEWER_ID_BYTES = 64;

    /**
     * Signs a token for one viewer and one video.
     *
     * @param string|int $viewerId   your id for the person watching; trimmed, then 1-64 bytes
     * @param int        $ttlSeconds 60 to 86400
     * @param string     $secret     the account's viewer signing secret, as shown (do not hex-decode)
     * @param string     $video      the Wolvy video id the token plays (required)
     * @param int|null   $now        Unix time to sign at (tests); default time()
     *
     * @throws InvalidArgumentException
     */
    public static function sign($viewerId, int $ttlSeconds, string $secret, string $video, ?int $now = null): string
    {
        return self::create($viewerId, $ttlSeconds, $secret, $video, $now)['vt'];
    }

    /**
     * Like sign(), with the expiry as well.
     *
     * @param string|int $viewerId
     * @return array{vt: string, expires_at: int, viewer_id: string, video: string}
     *
     * @throws InvalidArgumentException
     */
    public static function create($viewerId, int $ttlSeconds, string $secret, string $video, ?int $now = null): array
    {
        $vid = self::normaliseViewerId($viewerId);
        self::checkVideoId($video);

        if ($ttlSeconds < self::MIN_TTL || $ttlSeconds > self::MAX_TTL) {
            throw new InvalidArgumentException(sprintf(
                'The viewer token lifetime must be between %d and %d seconds; %d given.',
                self::MIN_TTL,
                self::MAX_TTL,
                $ttlSeconds
            ));
        }
        if ($secret === '') {
            throw new InvalidArgumentException('The viewer signing secret is empty.');
        }

        $exp = ($now !== null ? $now : time()) + $ttlSeconds;
        // Default flags on purpose: the API signs json_encode(['vid' => …, 'exp' => …, 'video' => …])
        // without any, in this order.
        $json = json_encode(['vid' => $vid, 'exp' => $exp, 'video' => $video]);
        if (!is_string($json)) {
            throw new InvalidArgumentException('The viewer id could not be encoded as JSON.');
        }
        $payload = self::base64url($json);
        $sig = self::base64url(hash_hmac('sha256', $payload, $secret, true));

        return ['vt' => $payload . '.' . $sig, 'expires_at' => $exp, 'viewer_id' => $vid, 'video' => $video];
    }

    /**
     * Checks a token the way Wolvy's player backend does: signature, expiry, an `exp` at most 30
     * days ahead, and a "video" claim equal to $videoId. Returns the viewer id, or null when the
     * token is not valid for that video. Useful in tests, and to check that a stored secret is
     * still the account's.
     */
    public static function verify(?string $vt, ?string $secret, string $videoId, ?int $now = null): ?string
    {
        if ($vt === null || $vt === '' || $secret === null || $secret === '' || $videoId === '') {
            return null;
        }
        $parts = explode('.', $vt);
        if (count($parts) !== 2) {
            return null;
        }
        [$payloadB64, $sigB64] = $parts;

        $expected = hash_hmac('sha256', $payloadB64, $secret, true);
        $provided = base64_decode(strtr($sigB64, '-_', '+/'), true);
        if ($provided === false || !hash_equals($expected, $provided)) {
            return null;
        }

        $payload = self::decode($vt);
        if ($payload === null || !isset($payload['vid']) || !isset($payload['exp'])) {
            return null;
        }
        $now = $now !== null ? $now : time();
        $exp = (int) $payload['exp'];
        if ($now > $exp || $exp - $now > self::VERIFY_MAX_TTL) {
            return null;
        }
        // The token must name this video, as at playback.
        if (!is_string($payload['video'] ?? null) || $payload['video'] === '' || !hash_equals($payload['video'], $videoId)) {
            return null;
        }
        $vid = (string) $payload['vid'];
        return $vid === '' ? null : $vid;
    }

    /**
     * The video a token names, read WITHOUT checking its signature, or null when it names none
     * (or is not a token). For catching mix-ups early, never for access decisions.
     */
    public static function videoOf(string $vt): ?string
    {
        $payload = self::decode($vt);
        $video = $payload['video'] ?? null;
        return is_string($video) && $video !== '' ? $video : null;
    }

    /**
     * Reads a token's payload WITHOUT checking its signature: `['vid' => …, 'exp' => …, 'video' => …]`, or null
     * when it is not a token. Never trust it for access decisions; use verify().
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $vt): ?array
    {
        $parts = explode('.', $vt);
        if (count($parts) !== 2) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * The viewer id as the API stores it: a string, trimmed, 1 to 64 bytes of valid UTF-8.
     *
     * @param mixed $viewerId
     * @throws InvalidArgumentException
     */
    public static function normaliseViewerId($viewerId): string
    {
        if (is_int($viewerId)) {
            $viewerId = (string) $viewerId;
        }
        if (!is_string($viewerId)) {
            throw new InvalidArgumentException('The viewer id must be a string (or an integer).');
        }
        $vid = trim($viewerId); // the API trims viewer_id before signing
        if ($vid === '') {
            throw new InvalidArgumentException('The viewer id is empty.');
        }
        if (strlen($vid) > self::MAX_VIEWER_ID_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The viewer id is %d bytes; at most %d are allowed.',
                strlen($vid),
                self::MAX_VIEWER_ID_BYTES
            ));
        }
        if (preg_match('//u', $vid) !== 1) {
            throw new InvalidArgumentException('The viewer id is not valid UTF-8.');
        }
        return $vid;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function checkVideoId(string $video): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $video) !== 1) {
            throw new InvalidArgumentException($video === ''
                ? 'A viewer token must name the video it plays: pass the Wolvy video id.'
                : 'The video must be a Wolvy video id: 1 to 64 letters, digits, "-" or "_".');
        }
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
