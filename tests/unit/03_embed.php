<?php
/**
 * embedUrl() and deepLink() against docs.wolvy.net/embedding/ and the API's own strings
 * (Playback::createToken: `<embed>?vt=<rawurlencode(vt)>`, `wolvy://play/{id}/{b64}/{vt}`).
 */

use Wolvy\Embed;
use Wolvy\Exception\InvalidArgumentException;
use Wolvy\Wolvy;

T::section('Embed URLs');

$VIDEO = 'a1b2c3d4e5f60718293a';
$VT = 'eyJ2aWQiOiJ1c2VyLTg4MTMiLCJleHAiOjE3OTAwMDAwMDB9.XBNWyMiuLw0y7WlQkzO5s2N-eI8Ltxhs4N0uvDHM4ZM';

T::run('the documented URL', function () use ($VIDEO, $VT) {
    T::same(Wolvy::embedUrl($VIDEO, 42), 'https://embed.wolvy.stream/a1b2c3d4e5f60718293a/NDI=', 'unsigned: account 42 is NDI=');
    T::same(Wolvy::embedUrl($VIDEO, 42, $VT), 'https://embed.wolvy.stream/a1b2c3d4e5f60718293a/NDI=?vt=' . $VT, 'signed: ?vt= appended');
    T::same(\Wolvy\embedUrl($VIDEO, '42', $VT), Wolvy::embedUrl($VIDEO, 42, $VT), '\\Wolvy\\embedUrl() and a string account id agree');
    T::same(Wolvy::embedUrl($VIDEO, 42, ''), Wolvy::embedUrl($VIDEO, 42), 'an empty token gives the unsigned URL');
    T::same(Wolvy::embedUrl($VIDEO, 1234567, null), 'https://embed.wolvy.stream/a1b2c3d4e5f60718293a/MTIzNDU2Nw==', 'base64 of the decimal string, with padding (as the API)');
    T::same(Wolvy::embedUrl($VIDEO, 42, 'a+b/c=d'), 'https://embed.wolvy.stream/a1b2c3d4e5f60718293a/NDI=?vt=a%2Bb%2Fc%3Dd', 'a token is URL-encoded (rawurlencode, as the API)');
    T::same(Wolvy::embedUrl($VIDEO, 42, null, 'https://embed.example.test/'), 'https://embed.example.test/a1b2c3d4e5f60718293a/NDI=', 'another player host, trailing slash ignored');
    T::same(Embed::encodeAccountId(42), 'NDI=', 'Embed::encodeAccountId()');
});

T::run('deep link', function () use ($VIDEO, $VT) {
    T::same(Wolvy::deepLink($VIDEO, 42, $VT), 'wolvy://play/a1b2c3d4e5f60718293a/NDI=/' . $VT, 'wolvy://play/{video}/{base64 account}/{vt}');
    T::throws(function () use ($VIDEO) { Wolvy::deepLink($VIDEO, 42, ''); }, InvalidArgumentException::class, 'a deep link needs a token');
});

T::run('bad input', function () use ($VIDEO) {
    foreach (['' => 'empty', 'a/b' => 'a slash', 'a b' => 'a space', str_repeat('a', 65) => '65 characters', '../x' => 'a path'] as $id => $label) {
        T::throws(function () use ($id) { Wolvy::embedUrl((string) $id, 42); }, InvalidArgumentException::class, "video id refused: {$label}");
    }
    T::ok(is_string(Wolvy::embedUrl(str_repeat('a', 64), 42)) && is_string(Wolvy::embedUrl('legacy_ID-1', 42)), 'any [A-Za-z0-9_-]{1,64} id is accepted');
    foreach ([0, -1, '0', '042', 'abc', '4.2', 4.2, null] as $account) {
        T::throws(function () use ($VIDEO, $account) { Embed::url($VIDEO, $account); }, InvalidArgumentException::class, 'account id refused: ' . var_export($account, true));
    }
});
