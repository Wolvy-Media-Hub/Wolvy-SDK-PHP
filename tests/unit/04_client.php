<?php
/**
 * The client against a fake transport: headers, idempotency keys, retries (network, 5xx, 429,
 * 409 request_in_progress), error mapping, rate-limit info, URL and query building, pagination
 * and typed results.
 */

use Wolvy\ApiResponse;
use Wolvy\Client;
use Wolvy\Collection;
use Wolvy\Exception\ApiException;
use Wolvy\Exception\AuthenticationException;
use Wolvy\Exception\ConflictException;
use Wolvy\Exception\ConnectionException;
use Wolvy\Exception\InvalidArgumentException;
use Wolvy\Exception\InvalidRequestException;
use Wolvy\Exception\NotFoundException;
use Wolvy\Exception\PermissionException;
use Wolvy\Exception\RateLimitException;
use Wolvy\Exception\ServerException;
use Wolvy\Exception\UnprocessableEntityException;
use Wolvy\Exception\WolvyException;
use Wolvy\Model;
use Wolvy\WolvyObject;

T::section('Client: requests, retries, errors');

$KEY = 'wv_live_' . str_repeat('A', 32);
$VIDEO = ['id' => 'a1b2c3d4e5f60718293a', 'object' => 'video', 'title' => 'Onboarding', 'status' => 'ready', 'folder_id' => null, 'tags' => ['a'], 'captions' => [['label' => 'English', 'language_code' => 'en']]];
$RATE = ['X-RateLimit-Limit' => '120', 'X-RateLimit-Remaining' => '117', 'X-RateLimit-Reset' => '1790000060', 'X-RateLimit-Daily-Limit' => '10000', 'X-RateLimit-Daily-Remaining' => '9000'];

/** A client on a fake transport; $sleeps collects every pause. */
function fake_client(FakeTransport $transport, ?array &$sleeps = null, array $options = []): Client
{
    $sleeps = [];
    return new Client('wv_live_' . str_repeat('A', 32), $options + [
        'transport' => $transport,
        'sleep'     => function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ]);
}

T::run('headers', function () use ($VIDEO) {
    $t = new FakeTransport(FakeTransport::json(200, $VIDEO), FakeTransport::json(202, $VIDEO), FakeTransport::json(200, $VIDEO), FakeTransport::json(204, null));
    $c = fake_client($t);
    $c->videos->retrieve('a1b2c3d4e5f60718293a');
    $get = $t->last();
    T::same($get['method'], 'GET', 'GET for retrieve');
    T::same($get['url'], 'https://api.wolvy.net/v1/videos/a1b2c3d4e5f60718293a', 'default base URL');
    T::same($get['headers']['Authorization'], 'Bearer wv_live_' . str_repeat('A', 32), 'Authorization: Bearer <key>');
    T::ok(preg_match('#^wolvy-php/\d+\.\d+\.\d+ PHP/\d+\.\d+\.\d+#', $get['headers']['User-Agent']) === 1, 'User-Agent: wolvy-php/<version> PHP/<version>', $get['headers']['User-Agent']);
    T::same($get['headers']['User-Agent'], 'wolvy-php/' . Client::VERSION . ' PHP/' . PHP_VERSION, 'User-Agent carries this PHP version');
    T::ok(!isset($get['headers']['Idempotency-Key']) && !isset($get['headers']['Content-Type']) && $get['body'] === null, 'a GET has no Idempotency-Key, no body, no Content-Type');
    T::same($get['options'], ['timeout' => 80.0, 'connect_timeout' => 10.0, 'ca_bundle' => null], 'default timeouts reach the transport');

    $c->videos->create(['source_url' => 'https://media.example.com/a b.mp4', 'title' => 'Café/Week 1', 'folder_id' => null]);
    $post = $t->last();
    T::ok(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) ($post['headers']['Idempotency-Key'] ?? '')) === 1, 'a POST carries a UUID v4 Idempotency-Key');
    T::same($post['headers']['Content-Type'], 'application/json', 'a POST with a body is JSON');
    T::same($post['body'], '{"source_url":"https://media.example.com/a b.mp4","title":"Café/Week 1","folder_id":null}', 'body: compact JSON, nulls kept, slashes and UTF-8 unescaped');

    $c->videos->update('a1b2c3d4e5f60718293a', ['title' => 'x']);
    T::ok(!isset($t->last()['headers']['Idempotency-Key']) && $t->last()['method'] === 'PATCH', 'a PATCH has no Idempotency-Key');
    $c->videos->delete('a1b2c3d4e5f60718293a');
    T::ok($t->last()['method'] === 'DELETE' && $t->last()['body'] === null, 'DELETE without a body');
    T::same($c->getLastResponse()->getStatus(), 204, 'delete() returns nothing; the 204 is the last response');
});

T::run('idempotency key options', function () use ($VIDEO) {
    $t = new FakeTransport(FakeTransport::json(201, ['object' => 'folder', 'id' => 1]), FakeTransport::json(201, ['object' => 'folder', 'id' => 2]), FakeTransport::json(201, ['object' => 'folder', 'id' => 3]));
    $c = fake_client($t);
    $c->folders->create(['name' => 'a']);
    $first = $t->last()['headers']['Idempotency-Key'];
    $c->folders->create(['name' => 'a']);
    T::ok($t->last()['headers']['Idempotency-Key'] !== $first, 'each call gets its own key');
    $c->folders->create(['name' => 'b'], ['idempotency_key' => 'order-42-folder']);
    T::same($t->last()['headers']['Idempotency-Key'], 'order-42-folder', 'a key passed in $opts is used as is');
    T::throws(function () use ($c) { $c->folders->create(['name' => 'b'], ['idempotency_key' => str_repeat('k', 256)]); }, InvalidArgumentException::class, 'a key over 255 characters is refused before sending');
    T::throws(function () use ($c) { $c->folders->create(['name' => 'b'], ['idempotency_kye' => 'x']); }, InvalidArgumentException::class, 'an unknown request option is refused (typo guard)');
});

T::run('retry after a network error reuses the key and the body', function () use ($VIDEO) {
    $t = new FakeTransport(FakeTransport::down(), FakeTransport::down('Operation timed out', 28), FakeTransport::json(202, $VIDEO, ['Idempotent-Replay' => 'true']));
    $c = fake_client($t, $sleeps);
    $video = $c->videos->create(['source_url' => 'https://media.example.com/a.mp4']);
    T::same(count($t->requests), 3, 'three attempts (max_retries 2)');
    T::same(count(array_unique(array_column(array_column($t->requests, 'headers'), 'Idempotency-Key'))), 1, 'the same Idempotency-Key on every attempt');
    T::same(count(array_unique(array_column($t->requests, 'body'))), 1, 'the same body bytes on every attempt');
    T::ok($video instanceof Model\Video && $video->id === 'a1b2c3d4e5f60718293a', 'the replayed answer is returned');
    T::ok($c->getLastResponse()->isIdempotentReplay() && $c->getLastResponse()->getAttempts() === 3, 'the response knows it was a replay after 3 attempts');
    T::ok(count($sleeps) === 2 && $sleeps[0] >= 0.25 && $sleeps[0] <= 0.5 && $sleeps[1] >= 0.5 && $sleeps[1] <= 1.0, 'backoff with jitter: about 0.5 s then 1 s', json_encode($sleeps));

    $t = new FakeTransport(FakeTransport::down(), FakeTransport::down(), FakeTransport::down());
    $c = fake_client($t);
    T::throws(function () use ($c) { $c->videos->list(); }, ConnectionException::class, 'a GET that never connects throws ConnectionException after 3 attempts', function ($e) use ($t) {
        return count($t->requests) === 3 && $e instanceof WolvyException;
    });

    $t = new FakeTransport(FakeTransport::down('Operation timed out', 28));
    $c = fake_client($t);
    T::throws(function () use ($c) { $c->folders->create(['name' => 'a'], ['idempotency_key' => false]); }, ConnectionException::class, 'a POST without a key is NOT retried after a network error', function ($e) use ($t) {
        return count($t->requests) === 1 && !isset($t->requests[0]['headers']['Idempotency-Key']) && $e->isTimeout();
    });

    $t = new FakeTransport(FakeTransport::down());
    $c = fake_client($t);
    T::throws(function () use ($c) { $c->videos->list([], ['max_retries' => 0]); }, ConnectionException::class, 'max_retries 0 in $opts: one attempt', function () use ($t) {
        return count($t->requests) === 1;
    });
});

T::run('429: wait as asked, then retry', function () use ($VIDEO, $RATE) {
    $t = new FakeTransport(
        FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'Rate limit exceeded: 120 requests per minute.', ['Retry-After' => '7', 'X-RateLimit-Remaining' => '0'] + $RATE),
        FakeTransport::json(202, $VIDEO)
    );
    $c = fake_client($t, $sleeps);
    $c->videos->create(['source_url' => 'https://media.example.com/a.mp4']);
    T::same($sleeps, [7.0], 'slept exactly Retry-After (7 s)');
    T::same($t->requests[0]['headers']['Idempotency-Key'], $t->requests[1]['headers']['Idempotency-Key'], 'the retry keeps the Idempotency-Key');

    $t = new FakeTransport(
        FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'x', ['X-RateLimit-Reset' => (string) (time() + 12), 'X-RateLimit-Remaining' => '0']),
        FakeTransport::json(200, $VIDEO)
    );
    $c = fake_client($t, $sleeps);
    $c->videos->retrieve('a');
    T::ok(count($sleeps) === 1 && $sleeps[0] >= 11 && $sleeps[0] <= 12, 'without Retry-After, waits until X-RateLimit-Reset', json_encode($sleeps));

    $t = new FakeTransport(FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'Daily rate limit exceeded: 10000 requests per day.', ['Retry-After' => '36000', 'X-RateLimit-Daily-Remaining' => '0'] + $RATE));
    $c = fake_client($t, $sleeps);
    $e = T::throws(function () use ($c) { $c->videos->list(); }, RateLimitException::class, 'a wait over max_retry_delay (the daily limit) is thrown at once');
    T::ok(count($t->requests) === 1 && $sleeps === [], 'no retry, no sleep');
    if ($e instanceof RateLimitException) {
        T::same($e->getRetryAfter(), 36000, 'getRetryAfter()');
        T::same($e->getErrorCode(), 'rate_limit_exceeded', 'getErrorCode()');
        T::same($e->getErrorType(), 'rate_limit_error', 'getErrorType()');
        T::same($e->getHttpStatus(), 429, 'getHttpStatus()');
        T::same($e->getCode(), 429, 'getCode() is the HTTP status');
        T::same($e->getRateLimit()->toArray(), ['limit' => 120, 'remaining' => 117, 'reset' => 1790000060, 'daily_limit' => 10000, 'daily_remaining' => 0, 'retry_after' => 36000], 'getRateLimit() carries every header');
        T::same($e->getRequestId(), 'req_' . str_repeat('cd', 12), 'getRequestId() from the body');
    }

    $t = new FakeTransport(
        FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'x', ['Retry-After' => '1']),
        FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'x', ['Retry-After' => '1']),
        FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'x', ['Retry-After' => '1'])
    );
    $c = fake_client($t, $sleeps);
    T::throws(function () use ($c) { $c->videos->list(); }, RateLimitException::class, 'still 429 after the retries: RateLimitException', function () use ($t) {
        return count($t->requests) === 3;
    });

    $t = new FakeTransport(FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'x', ['Retry-After' => '30']), FakeTransport::json(200, $VIDEO));
    $c = fake_client($t, $sleeps, ['max_retry_delay' => 10]);
    T::throws(function () use ($c) { $c->videos->retrieve('a'); }, RateLimitException::class, 'max_retry_delay 10: a 30 s Retry-After is not waited for');
});

T::run('5xx and 409', function () use ($VIDEO) {
    $t = new FakeTransport(
        FakeTransport::error(503, 'api_error', 'service_unavailable', 'Service temporarily unavailable.'),
        FakeTransport::error(500, 'api_error', 'internal_error', 'A server error occurred. Please try again.'),
        FakeTransport::json(200, $VIDEO)
    );
    $c = fake_client($t, $sleeps);
    $c->videos->retrieve('a');
    T::ok(count($t->requests) === 3 && count($sleeps) === 2, 'a GET is retried after 503 and 500');

    $t = new FakeTransport(FakeTransport::error(500, 'api_error', 'internal_error', 'x'), FakeTransport::json(201, ['object' => 'folder', 'id' => 9]));
    $c = fake_client($t, $sleeps);
    T::same($c->folders->create(['name' => 'x'])->id, 9, 'a POST with its key is retried after a 500 (the API released the key)');

    $t = new FakeTransport(FakeTransport::error(500, 'api_error', 'internal_error', 'x'));
    $c = fake_client($t, $sleeps);
    T::throws(function () use ($c) { $c->folders->create(['name' => 'x'], ['idempotency_key' => false]); }, ServerException::class, 'a POST without a key is not retried after a 500', function () use ($t) {
        return count($t->requests) === 1;
    });

    $t = new FakeTransport(new ApiResponse(502, ['Content-Type' => 'text/html'], '<html>Bad gateway</html>'), new ApiResponse(502, [], '<html/>'), new ApiResponse(502, [], '<html/>'));
    $c = fake_client($t, $sleeps);
    T::throws(function () use ($c) { $c->videos->list(); }, ServerException::class, 'a proxy\'s HTML 502: ServerException without an error code', function ($e) {
        return $e->getErrorCode() === null && $e->getHttpStatus() === 502 && strpos($e->getMessage(), '502') !== false;
    });

    $t = new FakeTransport(FakeTransport::error(409, 'invalid_request_error', 'request_in_progress', 'A request with this Idempotency-Key is still in progress.'), FakeTransport::json(202, $VIDEO, ['Idempotent-Replay' => 'true']));
    $c = fake_client($t, $sleeps);
    $c->videos->create(['source_url' => 'https://media.example.com/a.mp4']);
    T::ok(count($t->requests) === 2 && $t->requests[0]['headers']['Idempotency-Key'] === $t->requests[1]['headers']['Idempotency-Key'], '409 request_in_progress: waits and asks again with the same key');

    $t = new FakeTransport(FakeTransport::error(409, 'invalid_request_error', 'idempotency_key_reused', 'This Idempotency-Key was already used with a different request body.'));
    $c = fake_client($t, $sleeps);
    T::throws(function () use ($c) { $c->videos->create(['source_url' => 'x'], ['idempotency_key' => 'k1']); }, ConflictException::class, '409 idempotency_key_reused is thrown, not retried', function ($e) use ($t) {
        return count($t->requests) === 1 && $e->getErrorCode() === 'idempotency_key_reused';
    });
});

T::run('error mapping', function () use ($RATE) {
    $cases = [
        [400, 'invalid_request_error', 'unknown_parameter', InvalidRequestException::class, 'encryption'],
        [401, 'authentication_error', 'invalid_api_key', AuthenticationException::class, null],
        [403, 'permission_error', 'insufficient_scope', PermissionException::class, null],
        [404, 'invalid_request_error', 'video_not_found', NotFoundException::class, null],
        [405, 'invalid_request_error', 'method_not_allowed', InvalidRequestException::class, null],
        [409, 'invalid_request_error', 'chapter_overlap', ConflictException::class, null],
        [422, 'invalid_request_error', 'no_active_plan', UnprocessableEntityException::class, null],
        [418, 'invalid_request_error', 'teapot', ApiException::class, null],
    ];
    foreach ($cases as [$status, $type, $code, $class, $param]) {
        $t = new FakeTransport(FakeTransport::error($status, $type, $code, "Message for {$code}.", $RATE, $param));
        $c = fake_client($t, $sleeps);
        T::throws(function () use ($c) { $c->videos->retrieve('a'); }, $class, "{$status} {$code} → " . substr(strrchr($class, '\\'), 1), function ($e) use ($status, $type, $code, $param, $t) {
            return $e->getHttpStatus() === $status && $e->getErrorType() === $type && $e->getErrorCode() === $code
                && $e->getParam() === $param && $e->getMessage() === "Message for {$code}." && count($t->requests) === 1
                && $e->getRateLimit() !== null && $e->getRateLimit()->limit === 120;
        });
    }
    $t = new FakeTransport(FakeTransport::error(401, 'authentication_error', 'missing_api_key', 'x'));
    T::throws(function () use ($t) { fake_client($t)->videos->list(); }, AuthenticationException::class, 'a 401 has no rate-limit numbers (sent before the key is known)', function ($e) {
        return $e->getRateLimit() === null && $e->getRetryAfter() === null && $e->getRequestId() === 'req_' . str_repeat('cd', 12);
    });
    $t = new FakeTransport(new ApiResponse(200, ['X-Request-Id' => 'req_x'], 'not json'));
    T::throws(function () use ($t) { fake_client($t)->videos->list(); }, ApiException::class, 'a 200 that is not JSON is an ApiException', function ($e) {
        return $e->getRequestId() === 'req_x';
    });
});

T::run('URLs, paths and queries', function () use ($VIDEO) {
    $t = new FakeTransport();
    $c = fake_client($t, $sleeps, ['base_url' => 'http://127.0.0.1:8221/v1/']);
    $t->push(FakeTransport::json(204, null));
    $c->tags->remove('a1b2c3d4e5f60718293a', 'Week 1/ä');
    T::same($t->last()['url'], 'http://127.0.0.1:8221/v1/videos/a1b2c3d4e5f60718293a/tags/Week%201%2F%C3%A4', 'path segments are rawurlencoded; base_url trailing slash ignored');
    $t->push(FakeTransport::json(200, ['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null]));
    $c->videos->list(['status' => 'ready', 'folder_id' => 'root', 'q' => 'a&b c', 'tag' => null, 'created_after' => new DateTimeImmutable('2026-09-01 02:00:00', new DateTimeZone('Asia/Tokyo')), 'limit' => 20]);
    T::same($t->last()['url'], 'http://127.0.0.1:8221/v1/videos?status=ready&folder_id=root&q=a%26b%20c&created_after=2026-08-31T17%3A00%3A00Z&limit=20', 'query: nulls dropped, RFC 3986 encoding, a DateTime sent as UTC');
    $t->push(FakeTransport::json(200, ['object' => 'analytics_overview']));
    $c->analytics->overview(['from' => new DateTime('2026-09-01', new DateTimeZone('Asia/Tokyo')), 'to' => '2026-09-15']);
    T::same($t->last()['url'], 'http://127.0.0.1:8221/v1/analytics/overview?from=2026-09-01&to=2026-09-15', 'from/to as YYYY-MM-DD, the date as written');
    T::throws(function () use ($c) { $c->videos->list(['status' => ['ready']]); }, InvalidArgumentException::class, 'an array in the query is refused');
    $t->push(FakeTransport::json(200, ['object' => 'health', 'status' => 'ok', 'database' => 'ok']));
    $health = $c->health->check();
    T::ok($t->last()['url'] === 'http://127.0.0.1:8221/health' && !isset($t->last()['headers']['Authorization']), 'GET /health: no /v1 prefix, no API key');
    T::ok($health instanceof Model\Health && $health->status === 'ok', 'health is a Model\\Health');
    $t->push(FakeTransport::json(503, ['object' => 'health', 'status' => 'degraded', 'database' => 'unreachable']));
    T::same($c->health->check()->status, 'degraded', 'a 503 with a health body is returned, not thrown, and not retried');
    $t->push(FakeTransport::json(200, ['object' => 'list', 'data' => []]));
    $c->chapters->update('v', 0, ['title' => 'x']);
    T::same($t->last()['url'], 'http://127.0.0.1:8221/v1/videos/v/chapters/0', 'chapter index 0 is valid');
    foreach ([
        'folder id 0'           => function () use ($c) { $c->folders->retrieve(0); },
        'folder id "7a"'        => function () use ($c) { $c->folders->retrieve('7a'); },
        'endpoint id -1'        => function () use ($c) { $c->webhookEndpoints->retrieve(-1); },
        'empty video id'        => function () use ($c) { $c->videos->retrieve(''); },
        'video id ".."'         => function () use ($c) { $c->videos->delete('..'); },
        'viewer id with "/"'    => function () use ($c) { $c->viewers->retrieve('a/b'); },
        'a list as body'        => function () use ($c) { $c->folders->create(['a', 'b']); },
        'invalid UTF-8 in body' => function () use ($c) { $c->folders->create(['name' => "\xff"]); },
    ] as $label => $fn) {
        T::throws($fn, InvalidArgumentException::class, "refused before sending: {$label}");
    }
    T::same(count($t->requests), 6, 'none of the refused calls reached the transport');
    $t->push(FakeTransport::json(202, ['object' => 'webhook_delivery', 'id' => 5, 'status' => 'pending']));
    $c->webhookEndpoints->sendTest(3);
    T::ok($t->last()['body'] === null && !isset($t->last()['headers']['Content-Type']) && isset($t->last()['headers']['Idempotency-Key']), 'a POST without parameters sends no body, but a key');
    $t->push(FakeTransport::json(200, ['object' => 'video_tags', 'video_id' => 'v', 'tags' => []]));
    $c->tags->replace('v', []);
    T::same($t->last()['body'], '{"tags":[]}', 'replace with [] sends {"tags":[]}');
    $t->push(FakeTransport::json(200, $VIDEO));
    $c->request('GET', '/videos/x', ['a' => 1]);
    T::same($t->last()['url'], 'http://127.0.0.1:8221/v1/videos/x?a=1', 'request(): the escape hatch for unwrapped routes');
});

T::run('options and configuration', function () {
    T::throws(function () { new Client('k', ['base_uri' => 'x']); }, InvalidArgumentException::class, 'an unknown client option is refused');
    T::throws(function () { new Client('k', ['base_url' => 'api.wolvy.net']); }, InvalidArgumentException::class, 'a relative base_url is refused');
    T::throws(function () { new Client('k', ['timeout' => 0]); }, InvalidArgumentException::class, 'timeout 0 is refused');
    T::throws(function () { new Client('k', ['max_retries' => -1]); }, InvalidArgumentException::class, 'negative max_retries is refused');
    T::throws(function () { new Client("wv_live_x\n"); }, InvalidArgumentException::class, 'a key with a newline (copied from a file) is refused');
    T::throws(function () { new Client('k', ['account_id' => 'abc']); }, InvalidArgumentException::class, 'a non-numeric account_id is refused');
    $c = new Client(null, ['transport' => new FakeTransport()]);
    T::throws(function () use ($c) { $c->videos->list(); }, InvalidArgumentException::class, 'without an API key, API calls say so');
    $c = new Client('', ['viewer_sign_secret' => 'd80d15d09b7c0e56b4d787f6a212ed8bf8d53c761b6df521278f030cb12a7781', 'account_id' => '42']);
    T::ok(\Wolvy\ViewerToken::verify($c->signViewerToken('user-8813', 600, 'vidA1'), 'd80d15d09b7c0e56b4d787f6a212ed8bf8d53c761b6df521278f030cb12a7781', 'vidA1') === 'user-8813', 'helpers work without an API key (an empty one, as from an unset env var)');
    T::same($c->embedUrl('a1b2c3d4e5f60718293a'), 'https://embed.wolvy.stream/a1b2c3d4e5f60718293a/NDI=', '$client->embedUrl() with the account_id option, no API call');
    T::throws(function () { (new Client('k'))->signViewerToken('u', 600, 'vidA1'); }, InvalidArgumentException::class, 'signViewerToken() without viewer_sign_secret is refused');
    T::throws(function () { (new Client('k'))->verifyWebhook('{}', 't=1,v1=a'); }, InvalidArgumentException::class, 'verifyWebhook() without webhook_secret is refused');
    T::ok((new Client('k'))->videos() instanceof \Wolvy\Service\VideosService, '$client->videos() equals $client->videos (for the facade)');
    T::throws(function () { (new Client('k'))->nope; }, LogicException::class, 'an unknown service is an error');

    $t = new FakeTransport(FakeTransport::json(200, ['object' => 'account', 'id' => 77]), FakeTransport::json(200, ['object' => 'account', 'id' => 77]));
    $c = fake_client($t);
    T::same($c->embedUrl('vid1'), 'https://embed.wolvy.stream/vid1/Nzc=', 'without account_id, embedUrl() reads GET /v1/account…');
    $c->embedUrl('vid2');
    T::same(count($t->requests), 1, '…once per client');

    $t = new FakeTransport(FakeTransport::json(201, ['object' => 'playback_token', 'vt' => \Wolvy\Wolvy::signViewerToken('secret-check', 60, 'right-secret', 'v')]), FakeTransport::json(201, ['object' => 'playback_token', 'vt' => \Wolvy\Wolvy::signViewerToken('secret-check', 60, 'right-secret', 'v')]));
    T::ok(fake_client($t, $s, ['viewer_sign_secret' => 'right-secret'])->viewerSignSecretIsCurrent('v'), 'viewerSignSecretIsCurrent(): true with the account\'s secret');
    T::ok(!fake_client($t, $s, ['viewer_sign_secret' => 'old-secret'])->viewerSignSecretIsCurrent('v'), 'viewerSignSecretIsCurrent(): false after a rotation');
});

T::section('Client: results and pagination');

T::run('typed objects', function () use ($VIDEO) {
    $t = new FakeTransport(FakeTransport::json(200, $VIDEO), FakeTransport::json(200, ['object' => 'player_settings', 'colors' => ['player' => '#7e3dd9', 'accent' => '#000', 'background' => '#fff'], 'allowed_domains' => [], 'allowed_apps' => [['platform' => 'android', 'id' => 'com.example.app']], 'geo' => ['mode' => 'off', 'countries' => [], 'except_ips' => []]]));
    $c = fake_client($t);
    $video = $c->videos->retrieve('a1b2c3d4e5f60718293a');
    T::ok($video instanceof Model\Video && $video instanceof WolvyObject, 'a video is a Model\\Video');
    T::same([$video->title, $video['title'], $video->folder_id, $video->missing], ['Onboarding', 'Onboarding', null, null], 'fields as properties or keys; absent ones read null');
    T::ok($video->isReady() && $video->isPlayable() && !$video->isFailed(), 'Video::isReady()/isPlayable()');
    T::ok($video->captions[0] instanceof WolvyObject && $video->captions[0]->language_code === 'en', 'nested objects are WolvyObjects; lists are arrays');
    T::same($video->toArray(), $VIDEO, 'toArray() is the decoded JSON');
    T::same(json_encode($video), json_encode($VIDEO), 'json_encode() gives the same JSON');
    T::ok(isset($video->title) && !isset($video->folder_id) && $video->has('folder_id'), 'isset() and has()');
    T::throws(function () use ($video) { $video->title = 'x'; }, LogicException::class, 'objects are read-only (property)');
    T::throws(function () use ($video) { $video['title'] = 'x'; }, LogicException::class, 'objects are read-only (key)');
    $settings = $c->account->retrievePlayerSettings();
    T::ok($settings instanceof Model\PlayerSettings && $settings->colors->accent === '#000' && $settings['colors']['player'] === '#7e3dd9', 'nested maps read both ways');
    T::same($settings->allowed_apps[0]->id, 'com.example.app', 'allowed_apps entries');
    T::same(json_encode(new WolvyObject([])), '{}', 'an empty object encodes as {}');

    $map = [
        'account' => Model\Account::class, 'video_status' => Model\VideoStatus::class, 'bulk_result' => Model\BulkResult::class,
        'folder' => Model\Folder::class, 'tag' => Model\Tag::class, 'video_tags' => Model\VideoTags::class, 'caption' => Model\Caption::class,
        'chapter' => Model\Chapter::class, 'moment' => Model\Moment::class, 'playback_token' => Model\PlaybackToken::class,
        'embed' => Model\Embed::class, 'analytics_overview' => Model\AnalyticsOverview::class, 'analytics_day' => Model\AnalyticsDay::class,
        'analytics_country' => Model\AnalyticsCountry::class, 'analytics_devices' => Model\AnalyticsDevices::class,
        'video_analytics' => Model\VideoAnalytics::class, 'usage' => Model\Usage::class, 'usage_day' => Model\UsageDay::class,
        'plan' => Model\Plan::class, 'viewer_session' => Model\ViewerSession::class, 'viewer' => Model\Viewer::class,
        'viewer_sharing' => Model\ViewerSharing::class, 'security_event' => Model\SecurityEvent::class,
        'webhook_endpoint' => Model\WebhookEndpoint::class, 'webhook_delivery' => Model\WebhookDelivery::class,
        'health' => Model\Health::class, 'list' => Collection::class, 'something_new' => WolvyObject::class,
    ];
    $wrong = [];
    foreach ($map as $object => $class) {
        if (get_class(WolvyObject::constructFrom(['object' => $object])) !== $class) {
            $wrong[] = $object;
        }
    }
    T::same($wrong, [], 'every documented `object` maps to its class; an unknown one to WolvyObject');
});

T::run('pagination', function () use ($VIDEO) {
    $page = function (array $ids, ?string $next) {
        return FakeTransport::json(200, ['object' => 'list', 'data' => array_map(function ($id) {
            return ['id' => $id, 'object' => 'video', 'title' => $id];
        }, $ids), 'has_more' => $next !== null, 'next_cursor' => $next]);
    };
    $t = new FakeTransport($page(['v1', 'v2'], 'c1'), $page(['v3', 'v4'], 'c2'), $page(['v5'], null));
    $c = fake_client($t);
    $list = $c->videos->list(['limit' => 2, 'status' => 'ready']);
    T::ok($list instanceof Collection && count($list) === 2 && $list->hasMore() && $list->getNextCursor() === 'c1', 'list(): one page, has_more, next_cursor');
    T::ok($list->first() instanceof Model\Video && $list->data[1]->id === 'v2', 'items are typed');
    $ids = [];
    foreach ($list as $video) {
        $ids[] = $video->id;
    }
    T::same($ids, ['v1', 'v2'], 'iterating a page walks that page only');
    T::same(count($t->requests), 1, 'no extra request until asked');
    $all = [];
    foreach ($list->autoPagingIterator() as $video) {
        $all[] = $video->id;
    }
    T::same($all, ['v1', 'v2', 'v3', 'v4', 'v5'], 'autoPagingIterator() walks every page');
    T::same(count($t->requests), 3, 'three requests for three pages');
    T::same($t->requests[1]['url'], 'https://api.wolvy.net/v1/videos?limit=2&status=ready&starting_after=c1', 'the next page keeps the filters and adds starting_after');
    T::same($t->requests[2]['url'], 'https://api.wolvy.net/v1/videos?limit=2&status=ready&starting_after=c2', 'and again with the new cursor');

    $t = new FakeTransport($page(['a'], 'x'), $page(['b'], null));
    $c = fake_client($t);
    $generator = $c->videos->listAll(['limit' => 1]);
    T::ok($generator instanceof Generator && count($t->requests) === 0, 'listAll() is lazy: nothing sent before iterating');
    T::same(array_map(function ($v) { return $v->id; }, iterator_to_array($generator, false)), ['a', 'b'], 'listAll() yields every item');

    $t = new FakeTransport($page(['a'], 'x'), $page([], null));
    $c = fake_client($t);
    $first = $c->sessions->listForVideo('vid', ['country' => 'EG']);
    $second = $first->nextPage();
    T::ok($second instanceof Collection && $second->isEmpty() && $second->nextPage() === null, 'nextPage() until null');
    T::same($t->requests[1]['url'], 'https://api.wolvy.net/v1/videos/vid/sessions?country=EG&starting_after=x', 'sub-resource lists page with their path');

    $t = new FakeTransport(FakeTransport::json(200, ['object' => 'list', 'data' => [['object' => 'caption', 'language_code' => 'en', 'label' => 'English']]]));
    $c = fake_client($t);
    $captions = $c->captions->create('vid', ['language_code' => 'en', 'content' => "WEBVTT\n"]);
    T::ok($captions instanceof Collection && !$captions->hasMore() && $captions->first() instanceof Model\Caption, 'a create that answers {object: list, data} (no has_more) is a Collection');
});

T::run('secrets stay out of dumps', function () {
    $key = 'wv_live_' . str_repeat('S', 32);
    $client = new Client($key, ['viewer_sign_secret' => 'viewer-secret-value', 'webhook_secret' => 'whsec_secret-value']);
    $dump = print_r($client, true) . var_export($client->__debugInfo(), true);
    ob_start();
    var_dump($client);
    $dump .= (string) ob_get_clean();
    T::ok(strpos($dump, $key) === false && strpos($dump, 'viewer-secret-value') === false && strpos($dump, 'whsec_secret-value') === false, 'var_dump/print_r of a client show neither the key nor the secrets');
    T::ok(strpos($dump, 'wv_live_SSSS') !== false, 'the key prefix is shown, to tell keys apart');
});

T::run('playback tokens: no Idempotency-Key by default, still retried', function () {
    $token = ['object' => 'playback_token', 'video_id' => 'vid1', 'viewer_id' => 'u', 'vt' => 'p.s'];

    $t = new FakeTransport(FakeTransport::json(201, $token));
    fake_client($t)->playback->createToken('vid1', ['viewer_id' => 'u']);
    T::ok(!isset($t->last()['headers']['Idempotency-Key']) && $t->last()['method'] === 'POST', 'createToken() sends no Idempotency-Key (the docs advise against one)');

    $t = new FakeTransport(FakeTransport::down('Operation timed out', 28), FakeTransport::json(201, $token));
    $result = fake_client($t, $sleeps)->playback->createToken('vid1', ['viewer_id' => 'u']);
    T::ok($result instanceof Model\PlaybackToken && count($t->requests) === 2 && count($sleeps) === 1, 'retried after a network error even without a key (minting is safe to repeat)');
    T::ok(!isset($t->requests[0]['headers']['Idempotency-Key']) && !isset($t->requests[1]['headers']['Idempotency-Key']), 'no key on the retry either');

    $t = new FakeTransport(FakeTransport::error(503, 'api_error', 'service_unavailable', 'x'), FakeTransport::error(500, 'api_error', 'internal_error', 'x'), FakeTransport::json(201, $token));
    fake_client($t, $sleeps)->playback->createToken('vid1', ['viewer_id' => 'u']);
    T::same(count($t->requests), 3, 'retried after 503 and 500');

    $t = new FakeTransport(FakeTransport::error(429, 'rate_limit_error', 'rate_limit_exceeded', 'x', ['Retry-After' => '4']), FakeTransport::json(201, $token));
    fake_client($t, $sleeps)->playback->createToken('vid1', ['viewer_id' => 'u']);
    T::ok(count($t->requests) === 2 && $sleeps === [4.0], 'retried after a 429, waiting Retry-After');

    $t = new FakeTransport(FakeTransport::error(400, 'invalid_request_error', 'invalid_parameter', 'x', [], 'ttl_seconds'));
    T::throws(function () use ($t) { fake_client($t)->playback->createToken('vid1', ['viewer_id' => 'u', 'ttl_seconds' => 5]); }, InvalidRequestException::class, 'a 400 is not retried', function () use ($t) {
        return count($t->requests) === 1;
    });

    $t = new FakeTransport(FakeTransport::down(), FakeTransport::json(201, $token));
    fake_client($t, $sleeps)->playback->createToken('vid1', ['viewer_id' => 'u'], ['idempotency_key' => 'mint-order-42']);
    T::ok($t->requests[0]['headers']['Idempotency-Key'] === 'mint-order-42' && $t->requests[1]['headers']['Idempotency-Key'] === 'mint-order-42', 'an explicit idempotency_key is sent, and kept on the retry');

    $t = new FakeTransport(FakeTransport::json(201, $token));
    fake_client($t)->playback->createToken('vid1', ['viewer_id' => 'u'], ['idempotency_key' => true]);
    T::ok(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) ($t->last()['headers']['Idempotency-Key'] ?? '')) === 1, 'idempotency_key true asks for a generated key');

    $t = new FakeTransport(FakeTransport::json(201, ['object' => 'folder', 'id' => 1]));
    fake_client($t)->folders->create(['name' => 'x']);
    T::ok(isset($t->last()['headers']['Idempotency-Key']), 'other POSTs still get a key by default');
});
