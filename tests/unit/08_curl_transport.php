<?php
/**
 * The cURL transport where no server is needed: a refused connection, a timeout and a
 * protocol it must not follow. (The integration suite runs it against the real API.)
 */

use Wolvy\Client;
use Wolvy\Exception\ConnectionException;
use Wolvy\Http\CurlTransport;

T::section('cURL transport');

T::run('no answer', function () {
    $transport = new CurlTransport();
    T::throws(function () use ($transport) {
        $transport->request('GET', 'http://127.0.0.1:1/v1/videos?viewer_id=secret-person', [], null, ['timeout' => 5, 'connect_timeout' => 2]);
    }, ConnectionException::class, 'a refused connection is a ConnectionException', function ($e) {
        return $e->getCurlErrno() === 7 && !$e->isTimeout() && strpos($e->getMessage(), 'secret-person') === false && strpos($e->getMessage(), '/v1/videos?…') !== false;
    });

    $sleeps = [];
    $client = new Client('wv_live_' . str_repeat('A', 32), ['base_url' => 'http://127.0.0.1:1/v1', 'sleep' => function (float $s) use (&$sleeps) {
        $sleeps[] = $s;
    }]);
    T::throws(function () use ($client) { $client->videos->list(); }, ConnectionException::class, 'through the client: thrown after the retries', function () use (&$sleeps) {
        return count($sleeps) === 2;
    });

    // 192.0.2.1 (TEST-NET-1) is never routed: the connect attempt can only time out (or be refused
    // at once by a host without a route, which is still no answer).
    $started = microtime(true);
    T::throws(function () use ($transport) {
        $transport->request('GET', 'http://192.0.2.1/', [], null, ['timeout' => 0.3, 'connect_timeout' => 0.3]);
    }, ConnectionException::class, 'an unreachable host gives up within the timeout');
    T::ok(microtime(true) - $started < 3, 'the timeout is honoured (sub-second values work)');

    T::throws(function () use ($transport) {
        $transport->request('GET', 'file:///etc/passwd', [], null, []);
    }, ConnectionException::class, 'only http and https are allowed');
});
