<?php
/**
 * signViewerToken(): the published test vector, Wolvy's own verifier, the API's limits and the
 * API's exact JSON spelling. Every token names its video (required by Wolvy since 2026-11-22).
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
$VIDEO = 'a1b2c3d4e5f60718293a';
$VECTOR = 'eyJ2aWQiOiJ1c2VyLTg4MTMiLCJleHAiOjE3OTAwMDAwMDAsInZpZGVvIjoiYTFiMmMzZDRlNWY2MDcxODI5M2EifQ.KPYNjR-agijPtfv9s1VbBOSs_QJmi3NMlQ7tsUXCY9M';
$EXP = 1790000000;

/** A token signed by hand from a JSON payload, as other code might write it. */
function hand_signed(string $json, string $secret): string
{
    $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    return $payload . '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
}

T::run('test vector', function () use ($SECRET, $VIDEO, $VECTOR, $EXP) {
    T::same(Wolvy::signViewerToken('user-8813', 3600, $SECRET, $VIDEO, $EXP - 3600), $VECTOR, 'Wolvy::signViewerToken matches the published vector');
    T::same(\Wolvy\signViewerToken('user-8813', 3600, $SECRET, $VIDEO, $EXP - 3600), $VECTOR, '\\Wolvy\\signViewerToken() matches it too');
    T::same(ViewerToken::sign('user-8813', 86400, $SECRET, $VIDEO, $EXP - 86400), $VECTOR, 'the same exp from another ttl gives the same token');
    $created = ViewerToken::create('user-8813', 3600, $SECRET, $VIDEO, $EXP - 3600);
    T::same([$created['expires_at'], $created['video']], [$EXP, $VIDEO], 'create() reports expires_at = now + ttl, and the video');
    [$payload, $sig] = explode('.', $VECTOR);
    $json = '{"vid":"user-8813","exp":1790000000,"video":"a1b2c3d4e5f60718293a"}';
    T::same(base64_decode(strtr($payload, '-_', '+/')), $json, 'payload is compact JSON: vid, exp, video');
    T::same(ViewerToken::decode($VECTOR), ['vid' => 'user-8813', 'exp' => 1790000000, 'video' => $VIDEO], 'decode() reads the payload');
    T::same(ViewerToken::videoOf($VECTOR), $VIDEO, 'videoOf() reads the video');
    T::ok(strpbrk($VECTOR, '+/=') === false, 'base64url without padding');
    T::ok($sig !== rtrim(strtr(base64_encode(hash_hmac('sha256', $json, $SECRET, true)), '+/', '-_'), '='), 'signed over the encoded payload, not the JSON');
});

T::run('origin verifier', function () use ($SECRET, $VIDEO) {
    $vt = Wolvy::signViewerToken('user-8813', 3600, $SECRET, $VIDEO);
    T::same(verify_viewer_token($vt, $SECRET, $VIDEO), 'user-8813', 'verify_viewer_token() accepts a fresh token for its video');
    T::same(verify_viewer_token($vt, $SECRET, 'vidB2'), null, 'another video is refused');
    T::same(verify_viewer_token($vt, $SECRET, null), null, 'no video given is refused');
    T::same(verify_viewer_token(hand_signed('{"vid":"user-8813","exp":' . (time() + 600) . '}', $SECRET), $SECRET, $VIDEO), null, 'a token that names no video is refused');
    T::same(verify_viewer_token(Wolvy::signViewerToken('user-8813', 86400, $SECRET, $VIDEO), $SECRET, $VIDEO), 'user-8813', 'the longest lifetime (24 h) verifies');
    T::same(verify_viewer_token(Wolvy::signViewerToken('user-8813', 60, $SECRET, $VIDEO), $SECRET, $VIDEO), 'user-8813', 'the shortest lifetime (60 s) verifies');
    T::same(verify_viewer_token($vt, strrev($SECRET), $VIDEO), null, 'a wrong secret is refused');
    T::same(verify_viewer_token(Wolvy::signViewerToken('user-8813', 3600, $SECRET, $VIDEO, time() - 7200), $SECRET, $VIDEO), null, 'an expired token is refused');
    [$payload, $sig] = explode('.', $vt);
    $forged = rtrim(strtr(base64_encode('{"vid":"user-0001","exp":' . (time() + 3600) . ',"video":"' . $VIDEO . '"}'), '+/', '-_'), '=') . '.' . $sig;
    T::same(verify_viewer_token($forged, $SECRET, $VIDEO), null, 'a changed payload is refused');
    T::same(verify_viewer_token($payload . '.' . strrev($sig), $SECRET, $VIDEO), null, 'a changed signature is refused');
    $hexKey = Wolvy::signViewerToken('user-8813', 3600, (string) hex2bin($SECRET), $VIDEO);
    T::same(verify_viewer_token($hexKey, $SECRET, $VIDEO), null, 'a hex-decoded secret gives a token Wolvy refuses (the docs\' warning)');
    $odd = Wolvy::signViewerToken('  ünï/côdé 42  ', 600, $SECRET, $VIDEO);
    T::same(verify_viewer_token($odd, $SECRET, $VIDEO), 'ünï/côdé 42', 'non-ASCII and "/" ids verify, trimmed');
});

T::run('own verify() agrees with the origin', function () use ($SECRET, $VIDEO) {
    $now = time();
    $v = ',"video":"' . $VIDEO . '"';
    $cases = [
        'fresh'          => Wolvy::signViewerToken('a', 3600, $SECRET, $VIDEO),
        'another video'  => Wolvy::signViewerToken('a', 3600, $SECRET, 'vidB2'),
        'no video'       => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now + 600) . '}'), '+/', '-_'), '='),
        'empty video'    => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now + 600) . ',"video":""}'), '+/', '-_'), '='),
        'video not text' => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now + 600) . ',"video":["' . $VIDEO . '"]}'), '+/', '-_'), '='),
        'expired'        => Wolvy::signViewerToken('a', 60, $SECRET, $VIDEO, $now - 120),
        'wrong secret'   => Wolvy::signViewerToken('a', 3600, 'other', $VIDEO),
        '30 days ahead'  => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now + 2592000 - 5) . $v . '}'), '+/', '-_'), '='),
        '31 days ahead'  => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now + 2678400) . $v . '}'), '+/', '-_'), '='),
        'exp in ms'      => rtrim(strtr(base64_encode('{"vid":"a","exp":' . ($now * 1000) . $v . '}'), '+/', '-_'), '='),
        'no exp'         => rtrim(strtr(base64_encode('{"vid":"a"' . $v . '}'), '+/', '-_'), '='),
        'three segments' => 'a.b.c',
    ];
    foreach ($cases as $name => $vt) {
        if (strpos($vt, '.') === false) {
            $vt .= '.' . rtrim(strtr(base64_encode(hash_hmac('sha256', $vt, $SECRET, true)), '+/', '-_'), '=');
        }
        T::same(ViewerToken::verify($vt, $SECRET, $VIDEO), verify_viewer_token($vt, $SECRET, $VIDEO), "verify() = origin for: {$name}");
    }
    T::same(ViewerToken::verify($cases['fresh'], $SECRET, $VIDEO), 'a', 'and the fresh one does verify');
});

T::run('the API\'s limits', function () use ($SECRET, $VIDEO) {
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken('u', 59, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'ttl 59 s is refused (API minimum 60)');
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken('u', 86401, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'ttl 86401 s is refused (API maximum 24 h)');
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken('u', 2592000, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'ttl 30 days is refused although playback would accept it');
    T::throws(function () use ($VIDEO) { Wolvy::signViewerToken('u', 3600, '', $VIDEO); }, InvalidArgumentException::class, 'an empty secret is refused');
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken('', 3600, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'an empty viewer id is refused');
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken(" \t\n", 3600, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'a blank viewer id is refused (trimmed like the API)');
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken(str_repeat('x', 65), 3600, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'a 65-byte viewer id is refused');
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken(str_repeat('é', 33), 3600, $SECRET, $VIDEO); }, InvalidArgumentException::class, '33 two-byte characters (66 bytes) are refused: the limit is bytes, as in the API');
    T::ok(is_string(Wolvy::signViewerToken(str_repeat('x', 64), 3600, $SECRET, $VIDEO)), 'a 64-byte viewer id is accepted');
    T::throws(function () use ($SECRET, $VIDEO) { Wolvy::signViewerToken("bad\xff", 3600, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'invalid UTF-8 is refused');
    T::throws(function () use ($SECRET, $VIDEO) { ViewerToken::sign(['u'], 3600, $SECRET, $VIDEO); }, InvalidArgumentException::class, 'a non-string viewer id is refused');
    T::same(Wolvy::signViewerToken(42, 3600, $SECRET, $VIDEO, 1000), Wolvy::signViewerToken('42', 3600, $SECRET, $VIDEO, 1000), 'an integer id is signed as its string, like the API');
    T::same(Wolvy::signViewerToken("  user-1 \n", 3600, $SECRET, $VIDEO, 1000), Wolvy::signViewerToken('user-1', 3600, $SECRET, $VIDEO, 1000), 'the id is trimmed, like the API');
});

T::run('the video is required', function () use ($SECRET) {
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken('u', 3600, $SECRET, ''); }, InvalidArgumentException::class, 'an empty video is refused');
    foreach (['a/b' => 'a slash', 'a b' => 'a space', str_repeat('a', 65) => '65 characters', 'not a video id!' => 'punctuation'] as $id => $label) {
        T::throws(function () use ($SECRET, $id) { ViewerToken::sign('u', 3600, $SECRET, (string) $id); }, InvalidArgumentException::class, "video id refused: {$label}");
    }
    $threw = false;
    try {
        call_user_func('Wolvy\\Wolvy::signViewerToken', 'u', 3600, $SECRET); // a 1.x call
    } catch (\ArgumentCountError $e) {
        $threw = true;
    }
    T::ok($threw, 'a 1.x call without a video throws (ArgumentCountError), not a token Wolvy would refuse');
    T::same(ViewerToken::videoOf(hand_signed('{"vid":"u","exp":1}', $SECRET)), null, 'videoOf(): null for a token that names no video');
    T::same(ViewerToken::videoOf('not a token'), null, 'videoOf(): null for something that is not a token');
});

T::run('JSON spelled as the API spells it', function () use ($SECRET) {
    $vt = Wolvy::signViewerToken('ünï/côdé', 600, $SECRET, 'vidA1', 1000);
    $json = base64_decode(strtr(explode('.', $vt)[0], '-_', '+/'));
    // Written with escapes so the expectation cannot be re-encoded by an editor.
    T::same($json, "{\"vid\":\"\\u00fcn\\u00ef\\/c\\u00f4d\\u00e9\",\"exp\":1600,\"video\":\"vidA1\"}", 'PHP\'s default json_encode: "/" and non-ASCII escaped; video last');
});

$apiMint = getenv('WOLVY_API_VIEWER_TOKEN') ?: dirname(__DIR__, 3) . '/Wolvy-APIs/app/src/Wire/ViewerToken.php';
if (is_readable($apiMint) && PHP_VERSION_ID >= 70400) {
    T::run('the API\'s own minting code', function () use ($apiMint, $SECRET, $VIDEO) {
        require_once $apiMint;
        echo '  (API source: ', realpath($apiMint), ')', PHP_EOL;
        $mint = new \ReflectionMethod(\Wolvy\Api\Wire\ViewerToken::class, 'mint');
        if ($mint->getNumberOfRequiredParameters() < 4) {
            echo '  (skipped: that Wolvy-APIs checkout still mints tokens without a video)', PHP_EOL;
            return;
        }
        foreach (['user-8813', ' ünï/côdé 42 ', '<script>"&\'', str_repeat('z', 64)] as $id) {
            $trimmed = trim($id); // the API's Validate::string() trims before minting
            for ($try = 0; $try < 3; $try++) {
                $second = time();
                $theirs = \Wolvy\Api\Wire\ViewerToken::mint($trimmed, $SECRET, 7200, $VIDEO);
                $mine = Wolvy::signViewerToken($id, 7200, $SECRET, $VIDEO, $second);
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
