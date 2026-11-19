<?php
/**
 * verify_viewer_token(), the function Wolvy's player backend runs on every signed play: the real
 * Wolvy-Backend/viewer_token.php when it can be found (env WOLVY_ORIGIN_VIEWER_TOKEN, or the
 * sibling checkout ../Wolvy-Backend), else a pinned transcription of it. WOLVY_ORIGIN_VERIFIER
 * says which one is in use.
 */

$origin = getenv('WOLVY_ORIGIN_VIEWER_TOKEN') ?: dirname(__DIR__, 3) . '/Wolvy-Backend/viewer_token.php';
if (!function_exists('verify_viewer_token') && is_readable($origin)) {
    require_once $origin;
    define('WOLVY_ORIGIN_VERIFIER', realpath($origin));
}
if (!function_exists('verify_viewer_token')) {
    /**
     * Transcription of Wolvy-Backend/viewer_token.php verify_viewer_token() as of 2026-11-19. A
     * second opinion: do not replace it with a call into the SDK.
     */
    function verify_viewer_token($vt, $secret)
    {
        if (!is_string($vt) || $vt === '' || !is_string($secret) || $secret === '') {
            return null;
        }
        $parts = explode('.', $vt);
        if (count($parts) !== 2) {
            return null;
        }
        list($payloadB64, $sigB64) = $parts;
        $expected = hash_hmac('sha256', $payloadB64, $secret, true);
        $provided = base64_decode(strtr($sigB64, '-_', '+/'), true);
        if ($provided === false || !hash_equals($expected, $provided)) {
            return null;
        }
        $json = base64_decode(strtr($payloadB64, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload) || !isset($payload['vid'])) {
            return null;
        }
        if (!isset($payload['exp']) || time() > (int) $payload['exp']) {
            return null;
        }
        $maxTtl = (int) (getenv('VIEWER_TOKEN_MAX_TTL') ?: 2592000);
        if ((int) $payload['exp'] - time() > $maxTtl) {
            return null;
        }
        $vid = (string) $payload['vid'];
        return ($vid === '') ? null : $vid;
    }
}
if (!defined('WOLVY_ORIGIN_VERIFIER')) {
    define('WOLVY_ORIGIN_VERIFIER', 'pinned transcription of Wolvy-Backend/viewer_token.php (2026-11-19)');
}
