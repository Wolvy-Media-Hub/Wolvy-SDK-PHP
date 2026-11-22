<?php
/**
 * signViewerToken(): the published test vector, Wolvy's own verifier, the API's limits and the
 * API's exact JSON spelling.
 *
 * The verifier is the origin's real Wolvy-Backend/viewer_token.php when it can be found (env
 * WOLVY_ORIGIN_VIEWER_TOKEN, or the sibling checkout ../Wolvy-Backend), else the pinned
 * transcription below. The API's own minting code (Wolvy-APIs/app/src/Wire/ViewerToken.php) is
 * compared too when it can be found (env WOLVY_API_VIEWER_TOKEN, or ../Wolvy-APIs).
 */

use Wolvy\Exception\InvalidArgumentException;
use Wolvy\ViewerToken;
use Wolvy\Wolvy;

T::section('Viewer tokens');

require_once dirname(__DIR__) . '/support/origin_verifier.php';
echo '  (verifier: ', WOLVY_ORIGIN_VERIFIER, ')', PHP_EOL;

// docs.wolvy.net/viewer-tokens/#test-vectors
$SECRET = 'd80d15d09b7c0e56b4d787f6a212ed8bf8d53c761b6df521278f030cb12a7781';
$VECTOR = 'eyJ2aWQiOiJ1c2VyLTg4MTMiLCJleHAiOjE3OTAwMDAwMDB9.XBNWyMiuLw0y7WlQkzO5s2N-eI8Ltxhs4N0uvDHM4ZM';
$EXP = 1790000000;

T::run('test vector', function () use ($SECRET, $VECTOR, $EXP) {
    T::same(Wolvy::signViewerToken('user-8813', 3600, $SECRET, $EXP - 3600), $VECTOR, 'Wolvy::signViewerToken matches the published vector');
    T::same(\Wolvy\signViewerToken('user-8813', 3600, $SECRET, $EXP - 3600), $VECTOR, '\\Wolvy\\signViewerToken() matches it too');
    T::same(ViewerToken::sign('user-8813', 86400, $SECRET, $EXP - 86400), $VECTOR, 'the same exp from another ttl gives the same token');
    $created = ViewerToken::create('user-8813', 3600, $SECRET, $EXP - 3600);
    T::same($created['expires_at'], $EXP, 'create() reports expires_at = now + ttl');
    [$payload, $sig] = explode('.', $VECTOR);
    T::same(base64_decode(strtr($payload, '-_', '+/')), '{"vid":"user-8813","exp":1790000000}', 'payload is compact JSON, vid before exp');
    T::same(ViewerToken::decode($VECTOR), ['vid' => 'user-8813', 'exp' => 1790000000], 'decode() reads the payload');
    T::ok(strpbrk($VECTOR, '+/=') === false, 'base64url without padding');
    T::ok($sig !== rtrim(strtr(base64_encode(hash_hmac('sha256', '{"vid":"user-8813","exp":1790000000}', $SECRET, true)), '+/', '-_'), '='), 'signed over the encoded payload, not the JSON');
});

T::run('origin verifier', function () use ($SECRET) {
    $vt = Wolvy::signViewerToken('user-8813', 3600, $SECRET);
    T::same(verify_viewer_token($vt, $SECRET), 'user-8813', 'verify_viewer_token() accepts a fresh token');
    T::same(verify_viewer_token(Wolvy::signViewerToken('user-8813', 86400, $SECRET), $SECRET), 'user-8813', 'the longest lifetime (24 h) verifies');
    T::same(verify_viewer_token(Wolvy::signViewerToken('user-8813', 60, $SECRET), $SECRET), 'user-8813', 'the shortest lifetime (60 s) verifies');
    T::same(verify_viewer_token($vt, strrev($SECRET)), null, 'a wrong secret is refused');
    T::same(verify_viewer_token(Wolvy::signViewerToken('user-8813', 3600, $SECRET, time() - 7200), $SECRET), null, 'an expired token is refused');
    [$payload, $sig] = explode('.', $vt);
    $forged = rtrim(strtr(base64_encode('{"vid":"user-0001","exp":' . (time() + 3600) . '}'), '+/', '-_'), '=') . '.' . $sig;
    T::same(verify_viewer_token($forged, $SECRET), null, 'a changed payload is refused');
    T::same(verify_viewer_token($payload . '.' . strrev($sig), $SECRET), null, 'a changed signature is refused');
    $hexKey = Wolvy::signViewerToken('user-8813', 3600, (string) hex2bin($SECRET));
    T::same(verify_viewer_token($hexKey, $SECRET), null, 'a hex-decoded secret gives a token Wolvy refuses (the docs\' warning)');
    $odd = Wolvy::signViewerToken('  ünï/côdé 42  ', 600, $SECRET);
    T::same(verify_viewer_token($odd, $SECRET), 'ünï/côdé 42', 'non-ASCII and "/" ids verify, trimmed');
});

T::run('own verify() agrees with the origin', function () use ($SECRET) {
    $now = time();
    $cases = [
        'fresh'          => Wolvy::signViewerToken('a', 3600, $SECRET),
        'expired'        => Wolvy::signViewerToken('a', 60, $SECRET, $now - 120),
        'wrong secret'   => Wolvy::signViewerToken('a', 3600, 'other'),
        '30 days ahead'  => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now + 2592000 - 5) . '}'), '+/', '-_'), '='),
        '31 days ahead'  => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now + 2678400) . '}'), '+/', '-_'), '='),
        'exp in ms'      => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now * 1000) . '}'), '+/', '-_'), '='),
        'no exp'         => rtrim(strtr(base64_encode('{"vid":"a"}'), '+/', '-_'), '='),
        'three segments' => 'a.b.c',
    ];
    foreach ($cases as $name => $vt) {
        if (strpos($vt, '.') === false) {
            $vt .= '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $vt, $SECRET, true)), '+/', '-_'), '=');
        }
        T::same(ViewerToken::verify($vt, $SECRET), verify_viewer_token($vt, $SECRET), "verify() = origin for: {$name}");
    }
});

T::run('the API\'s limits', function () use ($SECRET) {
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken('u', 59, $SECRET); }, InvalidArgumentException::class, 'ttl 59 s is refused (API minimum 60)');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken('u', 86401, $SECRET); }, InvalidArgumentException::class, 'ttl 86401 s is refused (API maximum 24 h)');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken('u', 2592000, $SECRET); }, InvalidArgumentException::class, 'ttl 30 days is refused although playback would accept it');
    T::throws(function () { Wolvy::signViewerToken('u', 3600, ''); }, InvalidArgumentException::class, 'an empty secret is refused');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken('', 3600, $SECRET); }, InvalidArgumentException::class, 'an empty viewer id is refused');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken(" \t\n", 3600, $SECRET); }, InvalidArgumentException::class, 'a blank viewer id is refused (trimmed like the API)');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken(str_repeat('x', 65), 3600, $SECRET); }, InvalidArgumentException::class, 'a 65-byte viewer id is refused');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken(str_repeat('é', 33), 3600, $SECRET); }, InvalidArgumentException::class, '33 two-byte characters (66 bytes) are refused: the limit is bytes, as in the API');
    T::ok(is_string(Wolvy::signViewerToken(str_repeat('x', 64), 3600, $SECRET)), 'a 64-byte viewer id is accepted');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken("bad\xff", 3600, $SECRET); }, InvalidArgumentException::class, 'invalid UTF-8 is refused');
    T::throws(function () use ($SECRET) { ViewerToken::sign(['u'], 3600, $SECRET); }, InvalidArgumentException::class, 'a non-string viewer id is refused');
    T::same(Wolvy::signViewerToken(42, 3600, $SECRET, 1000), Wolvy::signViewerToken('42', 3600, $SECRET, 1000), 'an integer id is signed as its string, like the API');
    T::same(Wolvy::signViewerToken("  user-1 \n", 3600, $SECRET, 1000), Wolvy::signViewerToken('user-1', 3600, $SECRET, 1000), 'the id is trimmed, like the API');
});

T::run('JSON spelled as the API spells it', function () use ($SECRET) {
    $vt = Wolvy::signViewerToken('ünï/côdé', 600, $SECRET, 1000);
    $json = base64_decode(strtr(explode('.', $vt)[0], '-_', '+/'));
    // Written with escapes so the expectation cannot be re-encoded by an editor.
    T::same($json, "{\"vid\":\"\\u00fcn\\u00ef\\/c\\u00f4d\\u00e9\",\"exp\":1600}", 'PHP\'s default json_encode: "/" and non-ASCII escaped');
});

$apiMint = getenv('WOLVY_API_VIEWER_TOKEN') ?: dirname(__DIR__, 3) . '/Wolvy-APIs/app/src/Wire/ViewerToken.php';
if (is_readable($apiMint) && PHP_VERSION_ID >= 70400) {
    T::run('the API\'s own minting code', function () use ($apiMint, $SECRET) {
        require_once $apiMint;
        echo '  (API source: ', realpath($apiMint), ')', PHP_EOL;
        foreach (['user-8813', ' ünï/côdé 42 ', '<script>"&\'', str_repeat('z', 64)] as $id) {
            $trimmed = trim($id); // the API's Validate::string() trims before minting
            for ($try = 0; $try < 3; $try++) {
                $second = time();
                $theirs = \Wolvy\Api\Wire\ViewerToken::mint($trimmed, $SECRET, 7200);
                $mine = Wolvy::signViewerToken($id, 7200, $SECRET, $second);
                if ($theirs['expires_at'] === $second + 7200) {
                    break; // same second on both sides
                }
            }
            T::same($mine, $theirs['vt'], 'byte-identical to Wire\\ViewerToken::mint() for ' . json_encode($id, JSON_UNESCAPED_UNICODE));
        }
    });
} else {
    echo '  (skipped: Wolvy-APIs source not found, set WOLVY_API_VIEWER_TOKEN to compare with it)', PHP_EOL;
}

T::run('a token bound to a video (bind_video)', function () use ($SECRET, $EXP) {
    $bound = ViewerToken::sign('user-8813', 3600, $SECRET, $EXP - 3600, 'vidA1');
    $payload = ViewerToken::decode($bound);
    T::same($payload['video'] ?? null, 'vidA1', 'the video claim is in the payload, after exp');
    T::same(array_keys($payload), ['vid', 'exp', 'video'], 'claim order as the API writes it');
    T::same(ViewerToken::verify($bound, $SECRET, $EXP - 60, 'vidA1'), 'user-8813', 'our verifier: its video');
    T::same(ViewerToken::verify($bound, $SECRET, $EXP - 60, 'vidB2'), null, 'our verifier: another video');
    T::same(ViewerToken::verify($bound, $SECRET, $EXP - 60), null, 'our verifier: no video given');
    T::same(Wolvy::signViewerToken('user-8813', 3600, $SECRET, $EXP - 3600, 'vidA1'), $bound, 'Wolvy::signViewerToken takes the video too');
    T::same(\Wolvy\signViewerToken('user-8813', 3600, $SECRET, $EXP - 3600, 'vidA1'), $bound, '\\Wolvy\\signViewerToken() too');
    T::throws(function () use ($SECRET) { ViewerToken::sign('u', 3600, $SECRET, null, 'not a video id!'); }, InvalidArgumentException::class, 'a bad video id is refused');
    if (substr((string) WOLVY_ORIGIN_VERIFIER, -16) === 'viewer_token.php' && (new \ReflectionFunction('verify_viewer_token'))->getNumberOfParameters() >= 3) {
        $fresh = ViewerToken::sign('user-8813', 3600, $SECRET, null, 'vidA1');
        T::same(verify_viewer_token($fresh, $SECRET, 'vidA1'), 'user-8813', "Wolvy's own verifier: its video");
        T::same(verify_viewer_token($fresh, $SECRET, 'vidB2'), null, "Wolvy's own verifier: another video");
    }
});
