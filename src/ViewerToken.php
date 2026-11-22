<?php

namespace Wolvy;

use Wolvy\Exception\InvalidArgumentException;

/**
 * Signed viewer tokens (`vt`), made locally with the account's viewer signing secret
 * (dashboard: Settings → Watermark → Signing secret; never send it to a browser or an app).
 *
 *     payload = base64url( json_encode({"vid": <viewer id>, "exp": <unix seconds>}) )
 *     sig     = base64url( HMAC-SHA256(secret, payload) )      // over the ENCODED payload
 *     vt      = payload . "." . sig
 *
 * sign() is byte-identical to `POST /v1/videos/{id}/playback-tokens` for the same viewer id,
 * lifetime and second: the same input checks (the id is trimmed, then 1 to 64 bytes; 60 to
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
     * Signs a token for one viewer.
     *
     * @param string|int $viewerId   your id for the person watching; trimmed, then 1-64 bytes
     * @param int        $ttlSeconds 60 to 86400
     * @param string     $secret     the account's viewer signing secret, as shown (do not hex-decode)
     * @param int|null   $now        Unix time to sign at (tests); default time()
     *
     * @throws InvalidArgumentException
     * @param string|null $video  a Wolvy video id: the token then plays only that video (the API's
     *                           bind_video). Default null: any video of the account.
     */
    public static function sign($viewerId, int $ttlSeconds, string $secret, ?int $now = null, ?string $video = null): string
    {
        return self::create($viewerId, $ttlSeconds, $secret, $now, $video)['vt'];
    }

    /**
     * Like sign(), with the expiry as well.
     *
     * @param string|int $viewerId
     * @return array{vt: string, expires_at: int, viewer_id: string}
     *
     * @throws InvalidArgumentException
     */
    public static function create($viewerId, int $ttlSeconds, string $secret, ?int $now = null, ?string $video = null): array
    {
        $vid = self::normaliseViewerId($viewerId);
        if ($video !== null && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $video) !== 1) {
            throw new InvalidArgumentException('The video must be a Wolvy video id: 1 to 64 letters, digits, "-" or "_".');
        }

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
        // Default flags on purpose: the API signs json_encode(['vid' => …, 'exp' => …]) without any.
        // With a video, the API's bind_video adds "video" after "exp", the same way.
        $claims = ['vid' => $vid, 'exp' => $exp];
        if ($video !== null) {
            $claims['video'] = $video;
        }
        $json = json_encode($claims);
        if (!is_string($json)) {
            throw new InvalidArgumentException('The viewer id could not be encoded as JSON.');
        }
        $payload = self::base64url($json);
        $sig = self::base64url(hash_hmac('sha256', $payload, $secret, true));

        return ['vt' => $payload . '.' . $sig, 'expires_at' => $exp, 'viewer_id' => $vid, 'video' => $video];
    }

    /**
     * Checks a token the way Wolvy's player backend does (signature, expiry, and an `exp` at most
     * 30 days ahead). Returns the viewer id, or null when the token is not valid. Useful in
     * tests, and to check that a stored secret is still the account's.
     */
    public static function verify(?string $vt, ?string $secret, ?int $now = null, ?string $videoId = null): ?string
    {
        if ($vt === null || $vt === '' || $secret === null || $secret === '') {
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
        // A token bound to a video verifies only for that video, as at playback.
        if (array_key_exists('video', $payload)
            && (!is_string($payload['video']) || $videoId === null || !hash_equals($payload['video'], $videoId))) {
            return null;
        }
        $vid = (string) $payload['vid'];
        return $vid === '' ? null : $vid;
    }

    /**
     * Reads a token's payload WITHOUT checking its signature: `['vid' => …, 'exp' => …]`, or null
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

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
