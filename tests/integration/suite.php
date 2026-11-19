<?php
/**
 * Integration suite: the SDK against a real, local copy of the Developer API (Wolvy-APIs) and its
 * database. Every documented endpoint is called once at least.
 *
 *     WOLVY_IT_FIXTURE=/path/fixture.json php tests/run.php integration
 *
 * The fixture is the JSON the seeding script writes: base_url, account_id, api_key (all scopes),
 * api_key_limited (videos:read only), api_key_tiny (all scopes, 3 requests a minute),
 * viewer_secret, video_ids (seven videos of the account, newest first: Algebra 1 and 2 in
 * folder course_a, Geometry in module_1 inside it, Physics playable, Chemistry queued, Biology
 * failed, History ready), other_video_id (another account's), folder_ids, session_ids (four
 * sessions of the newest video, the last one never played).
 *
 * Optional:
 *  - WOLVY_IT_WEBHOOK_WORKER: a shell command that runs the API's workers/webhook_dispatch.php
 *    once, and WOLVY_IT_UPSTREAM_LOG: the log of tests/integration/fake_upstream.php, which
 *    receives the deliveries (and answers the API's media-server and source-URL calls). Without
 *    them the real-delivery checks are skipped.
 *  - WOLVY_IT_DB_DSN (+ WOLVY_IT_DB_USER, WOLVY_IT_DB_PASS): read-only checks in the database.
 *  - WOLVY_IT_SKIP_429_WAIT=1: skip the check that waits out a real Retry-After (up to 60 s).
 *
 * It changes data (folders, tags, captions, chapters, a webhook endpoint, an ingested video):
 * seed again before running it a second time.
 */

use Wolvy\Client;
use Wolvy\Collection;
use Wolvy\Exception\AuthenticationException;
use Wolvy\Exception\ConflictException;
use Wolvy\Exception\ConnectionException;
use Wolvy\Exception\InvalidArgumentException;
use Wolvy\Exception\InvalidRequestException;
use Wolvy\Exception\NotFoundException;
use Wolvy\Exception\PermissionException;
use Wolvy\Exception\RateLimitException;
use Wolvy\Exception\ServerException;
use Wolvy\Exception\SignatureVerificationException;
use Wolvy\Exception\UnprocessableEntityException;
use Wolvy\Model;
use Wolvy\Wolvy;

require_once dirname(__DIR__) . '/support/origin_verifier.php';

T::section('Integration: setup');

$fixturePath = getenv('WOLVY_IT_FIXTURE');
if (!$fixturePath || !is_readable($fixturePath)) {
    T::ok(false, 'WOLVY_IT_FIXTURE points at a readable fixture file', 'set WOLVY_IT_FIXTURE=/path/fixture.json');
    return;
}
$fx = json_decode((string) file_get_contents($fixturePath), true);
$BASE = getenv('WOLVY_IT_BASE_URL') ?: $fx['base_url'];
$ACCOUNT = (int) $fx['account_id'];
$SECRET = $fx['viewer_secret'];
$V = $fx['video_ids'];
$MISSING = '00000000000000000000';
echo '  API: ', $BASE, PHP_EOL, '  verifier: ', WOLVY_ORIGIN_VERIFIER, PHP_EOL;

$db = null;
if (getenv('WOLVY_IT_DB_DSN')) {
    $db = new PDO(getenv('WOLVY_IT_DB_DSN'), getenv('WOLVY_IT_DB_USER') ?: 'root', getenv('WOLVY_IT_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$rec = new RecordingTransport();
$wolvy = new Client($fx['api_key'], ['base_url' => $BASE, 'transport' => $rec, 'viewer_sign_secret' => $SECRET]);

/** A client on the same API with other options. */
$make = function (string $key, array $options = []) use ($BASE): Client {
    return new Client($key, $options + ['base_url' => $BASE]);
};

/** The ids of the items of a list or generator. */
$ids = function ($items): array {
    $out = [];
    foreach ($items as $item) {
        $out[] = $item->id;
    }
    return $out;
};

T::section('Integration: health, account, identification');

T::run('health and account', function () use ($wolvy, $rec, $ACCOUNT, $db) {
    $health = $wolvy->health->check();
    T::ok($health instanceof Model\Health && $health->status === 'ok' && $health->database === 'ok', 'GET /health answers ok');
    T::ok(!isset($rec->last()['headers']['Authorization']) && substr($rec->last()['url'], -7) === '/health', 'GET /health is sent to the root, without a key');

    $account = $wolvy->account->retrieve();
    T::ok($account instanceof Model\Account && $account->id === $ACCOUNT, "GET /v1/account: account {$ACCOUNT}");
    T::same(count($account->api_key->scopes), 9, 'the key has all nine scopes');
    T::same($account->plan->protection, 'drm', 'the plan is Multi-DRM');
    $rate = $wolvy->getLastResponse()->getRateLimit();
    T::ok($rate !== null && $rate->limit === 600 && $rate->remaining < 600 && $rate->dailyLimit === 100000, 'X-RateLimit-* read from a success', json_encode($rate ? $rate->toArray() : null));
    $requestId = $wolvy->getLastResponse()->getRequestId();
    T::ok(preg_match('/^req_[0-9a-f]{24}$/', (string) $requestId) === 1, 'X-Request-Id is a req_ id');
    if ($db !== null) {
        $stmt = $db->prepare('SELECT user_agent, route, status FROM api_requests WHERE request_id = ?');
        $stmt->execute([$requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        T::same($row['user_agent'] ?? null, 'wolvy-php/' . Client::VERSION . ' PHP/' . PHP_VERSION, 'the API logged the SDK\'s User-Agent for that request');
    }

    $settings = $wolvy->account->retrievePlayerSettings();
    T::ok($settings instanceof Model\PlayerSettings && $settings->watermark->enabled === true && $settings->allowed_domains === ['academy.example.com'], 'GET /v1/settings/player');
    T::ok(is_array($settings->allowed_apps) && $settings->geo->mode === 'off' && $settings->viewer_link_lock === false, 'player settings include allowed_apps, geo and viewer_link_lock');
});

T::section('Integration: videos and pagination');

T::run('list and pages', function () use ($wolvy, $rec, $V, $ids, $fx) {
    $page = $wolvy->videos->list(['limit' => 3]);
    T::ok($page instanceof Collection && count($page) === 3 && $page->hasMore(), 'GET /v1/videos?limit=3: three videos and has_more');
    T::ok($page->first() instanceof Model\Video, 'items are Model\\Video');
    T::same($ids($page), array_slice($V, 0, 3), 'newest first');

    $before = $rec->count();
    $all = $ids($wolvy->videos->listAll(['limit' => 3]));
    T::same($all, $V, 'listAll(limit 3) walks every video in order');
    T::same($rec->count() - $before, 3, 'in three requests (3 + 3 + 1)');
    T::ok(strpos($rec->last()['url'], 'starting_after=') !== false && strpos($rec->last()['url'], 'limit=3') !== false, 'later pages carry starting_after and the same limit');

    $second = $page->nextPage();
    T::same($ids($second), array_slice($V, 3, 3), 'nextPage() gives the next three');

    T::same($ids($wolvy->videos->list(['status' => 'ready'])), [$V[0], $V[1], $V[2], $V[6]], 'status=ready');
    T::same($ids($wolvy->videos->list(['status' => 'playable'])), [$V[3]], 'status=playable');
    T::same($ids($wolvy->videos->list(['status' => 'failed'])), [$V[5]], 'status=failed');
    T::same($ids($wolvy->videos->list(['folder_id' => 'root'])), [$V[3], $V[4], $V[5], $V[6]], 'folder_id=root');
    T::same($ids($wolvy->videos->list(['folder_id' => $fx['folder_ids']['course_a']])), [$V[0], $V[1]], 'folder_id=<course A>');
    T::same($ids($wolvy->videos->list(['q' => 'algebra'])), [$V[0], $V[1]], 'q=algebra (search)');
    T::same($ids($wolvy->videos->list(['q' => $V[4]])), [$V[4]], 'q=<exact id>');
    T::same($ids($wolvy->videos->list(['q' => 'shapes / angles'])), [$V[2]], 'q with a slash and spaces, encoded');
});

T::run('retrieve, status, not found', function () use ($wolvy, $V, $ACCOUNT, $MISSING, $fx) {
    $video = $wolvy->videos->retrieve($V[0]);
    T::ok($video instanceof Model\Video && $video->title === 'Algebra 1' && $video->isReady(), 'GET /v1/videos/{id}');
    T::same($video->captions[0]->language_code, 'en', 'nested captions');
    T::same($video->embed_url, Wolvy::embedUrl($V[0], $ACCOUNT), 'the video\'s embed_url equals Wolvy::embedUrl()');
    $status = $wolvy->videos->retrieveStatus($V[4]);
    T::ok($status instanceof Model\VideoStatus && $status->status === 'queued', 'GET /v1/videos/{id}/status');

    foreach (['an unknown id' => $MISSING, 'another account\'s video' => $fx['other_video_id']] as $label => $id) {
        T::throws(function () use ($wolvy, $id) { $wolvy->videos->retrieve($id); }, NotFoundException::class, "404 video_not_found for {$label}", function ($e) {
            return $e->getHttpStatus() === 404 && $e->getErrorCode() === 'video_not_found' && $e->getErrorType() === 'invalid_request_error'
                && preg_match('/^req_[0-9a-f]{24}$/', (string) $e->getRequestId()) === 1 && $e->getRequestId() === $e->getResponse()->getRequestId()
                && $e->getRateLimit() !== null && $e->getRateLimit()->limit === 600;
        });
    }
    T::throws(function () use ($wolvy) { $wolvy->folders->retrieve(999999); }, NotFoundException::class, '404 folder_not_found', function ($e) {
        return $e->getErrorCode() === 'folder_not_found';
    });
    T::throws(function () use ($wolvy) { $wolvy->request('GET', '/no-such-thing'); }, NotFoundException::class, '404 unknown_endpoint, before authentication: no rate-limit numbers', function ($e) {
        return $e->getErrorCode() === 'unknown_endpoint' && $e->getRateLimit() === null;
    });
    T::throws(function () use ($wolvy) { $wolvy->request('PUT', '/videos', ['title' => 'x']); }, InvalidRequestException::class, '405 method_not_allowed with an Allow header', function ($e) {
        return $e->getHttpStatus() === 405 && $e->getErrorCode() === 'method_not_allowed' && strpos((string) $e->getResponse()->getHeader('Allow'), 'GET') !== false;
    });
    T::throws(function () use ($wolvy) { $wolvy->videos->list(['status' => 'done']); }, InvalidRequestException::class, '400 invalid_parameter names the param', function ($e) {
        return $e->getErrorCode() === 'invalid_parameter' && $e->getParam() === 'status';
    });
    T::throws(function () use ($wolvy) { $wolvy->videos->list(['colour' => 'red']); }, InvalidRequestException::class, '400 unknown_parameter for an unknown filter', function ($e) {
        return $e->getErrorCode() === 'unknown_parameter' && $e->getParam() === 'colour';
    });
    T::throws(function () use ($wolvy) { $wolvy->videos->list(['starting_after' => 'not-a-cursor']); }, InvalidRequestException::class, '400 invalid_cursor', function ($e) {
        return $e->getErrorCode() === 'invalid_cursor' && $e->getParam() === 'starting_after';
    });
});

T::section('Integration: playback tokens are byte-identical');

T::run('create a playback token, compare with local signing', function () use ($wolvy, $rec, $V, $ACCOUNT, $SECRET, $db, $make, $fx) {
    foreach ([['user-8813', 3600], ['  ünï/côdé 42  ', 600], ['<b>"quoted" & \'single\'</b>', 86400], [str_repeat('x', 64), 60]] as [$viewer, $ttl]) {
        $token = $wolvy->playback->createToken($V[0], ['viewer_id' => $viewer, 'ttl_seconds' => $ttl]);
        $exp = strtotime((string) $token->expires_at);
        $mine = Wolvy::signViewerToken($viewer, $ttl, $SECRET, $exp - $ttl);
        $name = json_encode($viewer, JSON_UNESCAPED_UNICODE) . ", {$ttl} s";
        T::ok($token instanceof Model\PlaybackToken && $token->vt === $mine, "vt from the API === Wolvy::signViewerToken() for {$name}", $token->vt . ' vs ' . $mine);
        T::same(verify_viewer_token($token->vt, $SECRET), trim($viewer), "the origin verifier accepts it ({$name})");
    }
    T::ok($rec->last()['method'] === 'POST' && !isset($rec->last()['headers']['Idempotency-Key']), 'the token request carried no Idempotency-Key');
    if ($db !== null) {
        // These are the first POSTs of this key in the suite: four mints, no stored replay.
        $stmt = $db->query("SELECT COUNT(*) FROM api_idempotency i JOIN user_api_keys k ON k.id = i.key_id WHERE k.name = 'sdk-it-all'");
        T::same((int) $stmt->fetchColumn(), 0, 'so the API stored no idempotency row for the mints');
    }

    $default = $wolvy->playback->createToken($V[1], ['viewer_id' => 'user-8813']);
    $exp = strtotime((string) $default->expires_at);
    T::same($default->vt, Wolvy::signViewerToken('user-8813', 21600, $SECRET, $exp - 21600), 'default lifetime: 21600 s, identical too');
    T::same($default->embed_url, Wolvy::embedUrl($V[1], $ACCOUNT, $default->vt), 'embed_url === Wolvy::embedUrl(video, account, vt)');
    T::same($default->deep_link, Wolvy::deepLink($V[1], $ACCOUNT, $default->vt), 'deep_link === Wolvy::deepLink()');
    T::ok(strpos((string) $default->iframe_html, htmlspecialchars($default->embed_url, ENT_QUOTES)) !== false, 'iframe_html carries the same URL');

    $lossy = new RecordingTransport(1);
    $client = $make($fx['api_key'], ['transport' => $lossy, 'sleep' => function () {}]);
    $retried = $client->playback->createToken($V[0], ['viewer_id' => 'user-lost', 'ttl_seconds' => 600]);
    T::ok($lossy->count() === 2 && !isset($lossy->log[0]['headers']['Idempotency-Key']) && !isset($lossy->log[1]['headers']['Idempotency-Key']), 'a lost mint answer is retried without a key');
    T::same(verify_viewer_token($retried->vt, $SECRET), 'user-lost', 'and the retried mint is a valid token');

    $key = 'mint-' . bin2hex(random_bytes(6));
    $first = $wolvy->playback->createToken($V[0], ['viewer_id' => 'user-replay', 'ttl_seconds' => 600], ['idempotency_key' => $key]);
    sleep(1);
    $again = $wolvy->playback->createToken($V[0], ['viewer_id' => 'user-replay', 'ttl_seconds' => 600], ['idempotency_key' => $key]);
    T::ok($again->vt === $first->vt && $wolvy->getLastResponse()->isIdempotentReplay(), 'an explicit idempotency_key still works: the API replays the same token');

    foreach ([59 => 'ttl 59 s', 86401 => 'ttl 86401 s'] as $ttl => $label) {
        T::throws(function () use ($wolvy, $V, $ttl) { $wolvy->playback->createToken($V[0], ['viewer_id' => 'u', 'ttl_seconds' => $ttl]); }, InvalidRequestException::class, "the API refuses {$label} (invalid_parameter ttl_seconds)", function ($e) {
            return $e->getErrorCode() === 'invalid_parameter' && $e->getParam() === 'ttl_seconds';
        });
        T::throws(function () use ($ttl, $SECRET) { Wolvy::signViewerToken('u', $ttl, $SECRET); }, InvalidArgumentException::class, "…and so does local signing ({$label})");
    }
    T::throws(function () use ($wolvy, $V) { $wolvy->playback->createToken($V[0], ['viewer_id' => str_repeat('x', 65)]); }, InvalidRequestException::class, 'the API refuses a 65-byte viewer id…');
    T::throws(function () use ($SECRET) { Wolvy::signViewerToken(str_repeat('x', 65), 600, $SECRET); }, InvalidArgumentException::class, '…and so does local signing');
});

T::run('embed URLs and the stored secret', function () use ($wolvy, $make, $fx, $V, $ACCOUNT, $SECRET) {
    $embed = $wolvy->playback->retrieveEmbed($V[2]);
    T::ok($embed instanceof Model\Embed && $embed->embed_url === Wolvy::embedUrl($V[2], $ACCOUNT), 'GET /v1/videos/{id}/embed === Wolvy::embedUrl()');
    T::same($wolvy->embedUrl($V[2]), $embed->embed_url, '$client->embedUrl() reads the account id from the API and agrees');
    T::same(\Wolvy\embedUrl($V[2], $ACCOUNT, 'tok.en'), $embed->embed_url . '?vt=tok.en', 'with a token: ?vt=');
    T::ok($wolvy->viewerSignSecretIsCurrent($V[0]), 'viewerSignSecretIsCurrent(): the stored secret is the account\'s');
    $stale = $make($fx['api_key'], ['viewer_sign_secret' => str_repeat('0', 64)]);
    T::ok(!$stale->viewerSignSecretIsCurrent($V[0]), 'viewerSignSecretIsCurrent(): false for another secret');
});

T::section('Integration: idempotency and retries');

T::run('a lost answer is retried with the same key and replayed', function () use ($make, $fx, $db) {
    $lossy = new RecordingTransport(1);
    $client = $make($fx['api_key'], ['transport' => $lossy, 'sleep' => function () {}]);
    $name = 'SDK idempotent ' . bin2hex(random_bytes(4));
    $folder = $client->folders->create(['name' => $name]);
    T::same($lossy->count(), 2, 'two attempts: the first answer was lost');
    T::same($lossy->log[0]['headers']['Idempotency-Key'], $lossy->log[1]['headers']['Idempotency-Key'], 'both attempts carried the same Idempotency-Key');
    T::same([$lossy->log[0]['status'], $lossy->log[1]['status']], [201, 201], 'the API created the folder on the first attempt');
    $first = $lossy->log[0]['response']->getJson();
    T::ok($folder instanceof Model\Folder && $folder->id === $first['id'], 'the retry returned the folder made by the first attempt');
    T::ok($client->getLastResponse()->isIdempotentReplay() && $client->getLastResponse()->getAttempts() === 2, 'answered from the idempotency store (Idempotent-Replay: true)');
    T::same(count($client->folders->list(['q' => $name])), 1, 'one folder exists, not two');

    // Both answers lost: the SDK gives up; the caller retries with the same key, safely.
    $lossy = new RecordingTransport(2);
    $client = $make($fx['api_key'], ['transport' => $lossy, 'sleep' => function () {}, 'max_retries' => 1]);
    $name = 'SDK idempotent ' . bin2hex(random_bytes(4));
    T::throws(function () use ($client, $name) { $client->folders->create(['name' => $name]); }, ConnectionException::class, 'every answer lost: ConnectionException after the retries');
    $key = $lossy->log[0]['headers']['Idempotency-Key'];
    $again = $client->folders->create(['name' => $name], ['idempotency_key' => $key]);
    T::ok($again->id === $lossy->log[0]['response']->getJson()['id'] && $client->getLastResponse()->isIdempotentReplay(), 'retrying by hand with the same key returns the same folder');
    T::same(count($client->folders->list(['q' => $name])), 1, 'still one folder');
    if ($db !== null) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM folders WHERE name = ?');
        $stmt->execute([$name]);
        T::same((int) $stmt->fetchColumn(), 1, 'one row in the database');
    }
});

T::run('a failed POST gives its key back (wave 4)', function () use ($wolvy, $rec) {
    $key = 'sdk-it-' . bin2hex(random_bytes(8));
    T::throws(function () use ($wolvy, $key) { $wolvy->folders->create(['name' => 'Released key', 'parent_id' => 999999], ['idempotency_key' => $key]); }, InvalidRequestException::class, 'POST with a bad parent_id fails (400 folder_not_found)', function ($e) {
        return $e->getErrorCode() === 'folder_not_found' && $e->getParam() === 'parent_id';
    });
    $folder = $wolvy->folders->create(['name' => 'Released key'], ['idempotency_key' => $key]);
    T::ok($folder instanceof Model\Folder && !$wolvy->getLastResponse()->isIdempotentReplay(), 'the corrected request with the same key runs (not 409, not a replay)');
    $before = $rec->count();
    T::throws(function () use ($wolvy, $key) { $wolvy->folders->create(['name' => 'Another body'], ['idempotency_key' => $key]); }, ConflictException::class, 'the key reused with another body: 409 idempotency_key_reused', function ($e) {
        return $e->getErrorCode() === 'idempotency_key_reused';
    });
    T::same($rec->count() - $before, 1, 'and not retried');
});

T::section('Integration: errors and rate limits');

T::run('authentication and scopes', function () use ($make, $fx) {
    T::throws(function () use ($make) { $make('wv_live_' . str_repeat('Z', 32))->account->retrieve(); }, AuthenticationException::class, '401 invalid_api_key for an unknown key, no rate-limit numbers', function ($e) {
        return $e->getErrorCode() === 'invalid_api_key' && $e->getErrorType() === 'authentication_error' && $e->getRateLimit() === null
            && $e->getResponse()->getHeader('WWW-Authenticate') !== null;
    });
    T::throws(function () use ($make) { $make('wv_live_short')->account->retrieve(); }, AuthenticationException::class, '401 invalid_api_key for a malformed key');
    $limited = $make($fx['api_key_limited']);
    T::ok(count($limited->videos->list(['limit' => 1])) === 1, 'the videos:read key can list videos');
    T::throws(function () use ($limited) { $limited->folders->create(['name' => 'nope']); }, PermissionException::class, '403 insufficient_scope, with the rate-limit numbers (wave 4)', function ($e) {
        return $e->getErrorCode() === 'insufficient_scope' && strpos($e->getMessage(), 'videos:write') !== false
            && $e->getRateLimit() !== null && $e->getRateLimit()->limit === 600;
    });
});

T::run('429 rate_limit_exceeded', function () use ($make, $fx) {
    $noRetry = $make($fx['api_key_tiny'], ['max_retries' => 0]);
    $limited = null;
    for ($i = 0; $i < 10 && $limited === null; $i++) {
        try {
            $noRetry->account->retrieve();
        } catch (RateLimitException $e) {
            $limited = $e;
        }
    }
    T::ok($limited !== null, 'the 3-a-minute key is refused within a few calls');
    if ($limited === null) {
        return;
    }
    $rate = $limited->getRateLimit();
    T::ok($limited->getErrorCode() === 'rate_limit_exceeded' && $limited->getHttpStatus() === 429, '429 rate_limit_exceeded');
    T::ok($limited->getRetryAfter() >= 1 && $limited->getRetryAfter() <= 60, 'Retry-After is the seconds to the next minute', (string) $limited->getRetryAfter());
    T::ok($rate !== null && $rate->limit === 3 && $rate->remaining === 0 && $rate->dailyLimit === 100000 && $rate->reset >= time(), 'the 429 carries X-RateLimit-* too (wave 4)', json_encode($rate ? $rate->toArray() : null));

    if (getenv('WOLVY_IT_SKIP_429_WAIT')) {
        echo '  (skipped: waiting out a real Retry-After)', PHP_EOL;
        return;
    }
    $sleeps = [];
    $retrying = $make($fx['api_key_tiny'], ['max_retries' => 2, 'max_retry_delay' => 61, 'sleep' => function (float $s) use (&$sleeps) {
        $sleeps[] = $s;
        usleep((int) ($s * 1000000));
    }]);
    $started = microtime(true);
    $account = $retrying->account->retrieve();
    $waited = microtime(true) - $started;
    T::ok($account instanceof Model\Account && $retrying->getLastResponse()->getAttempts() >= 2, 'with retries on, the SDK waits Retry-After and the call succeeds', 'attempts ' . $retrying->getLastResponse()->getAttempts() . ', slept ' . json_encode($sleeps));
    T::ok($sleeps !== [] && $sleeps[0] >= 1 && $sleeps[0] <= 60 && $waited >= $sleeps[0], sprintf('it slept the API\'s Retry-After (%.0f s, %.1f s in all)', $sleeps ? $sleeps[0] : -1, $waited));
});

T::section('Integration: every other endpoint');

$state = ['endpoint' => null, 'ingested' => null];

T::run('webhook endpoints (created first, so later events reach it)', function () use ($wolvy, &$state) {
    $endpoint = $wolvy->webhookEndpoints->create(['url' => 'https://203.0.113.10/hooks/sdk-it', 'events' => ['video.ready', 'video.deleted']]);
    T::ok($endpoint instanceof Model\WebhookEndpoint && preg_match('/^whsec_[0-9a-f]{48}$/', (string) $endpoint->secret) === 1, 'POST /v1/webhook-endpoints returns the whsec_ secret once');
    $state['endpoint'] = $endpoint;
    T::throws(function () use ($wolvy) { $wolvy->webhookEndpoints->create(['url' => 'http://203.0.113.10/hooks', 'events' => ['video.ready']]); }, UnprocessableEntityException::class, '422 invalid_source_url for http://', function ($e) {
        return $e->getErrorCode() === 'invalid_source_url';
    });
    T::throws(function () use ($wolvy) { $wolvy->webhookEndpoints->create(['url' => 'https://10.0.0.1/hooks', 'events' => ['video.ready']]); }, UnprocessableEntityException::class, '422 source_not_allowed for a private address', function ($e) {
        return $e->getErrorCode() === 'source_not_allowed';
    });
    T::throws(function () use ($wolvy) { $wolvy->webhookEndpoints->create(['url' => 'https://203.0.113.10/x', 'events' => ['webhook.test']]); }, InvalidRequestException::class, '400: webhook.test cannot be subscribed to');
    $list = $wolvy->webhookEndpoints->list();
    T::ok($list instanceof Collection && $list->first() instanceof Model\WebhookEndpoint && $list->first()->secret === null, 'GET /v1/webhook-endpoints: no secret in lists');
    T::same($wolvy->webhookEndpoints->retrieve($endpoint->id)->url, 'https://203.0.113.10/hooks/sdk-it', 'GET /v1/webhook-endpoints/{id}');
    $updated = $wolvy->webhookEndpoints->update($endpoint->id, ['events' => ['video.ready', 'video.deleted', 'caption.ready']]);
    T::same($updated->events, ['video.ready', 'video.deleted', 'caption.ready'], 'PATCH /v1/webhook-endpoints/{id}');
    $spare = $wolvy->webhookEndpoints->create(['url' => 'https://203.0.113.10/hooks/spare', 'events' => ['payment.paid']]);
    $wolvy->webhookEndpoints->delete($spare->id);
    T::throws(function () use ($wolvy, $spare) { $wolvy->webhookEndpoints->retrieve($spare->id); }, NotFoundException::class, 'DELETE /v1/webhook-endpoints/{id}', function ($e) {
        return $e->getErrorCode() === 'webhook_endpoint_not_found';
    });
});

T::run('folders', function () use ($wolvy, $fx) {
    $top = $wolvy->folders->create(['name' => 'SDK Course']);
    $child = $wolvy->folders->create(['name' => 'SDK Lesson', 'parent_id' => $top->id]);
    T::ok($child instanceof Model\Folder && $child->parent_id === $top->id && count($child->path) === 2 && $child->path[0]->name === 'SDK Course', 'POST /v1/folders with parent_id: path is a breadcrumb');
    T::same($wolvy->folders->retrieve($top->id)->folder_count, 1, 'GET /v1/folders/{id}: folder_count');
    $renamed = $wolvy->folders->update((string) $child->id, ['name' => 'SDK Lesson 1', 'parent_id' => null]);
    T::ok($renamed->name === 'SDK Lesson 1' && $renamed->parent_id === null, 'PATCH /v1/folders/{id}: renamed and moved to the top level');
    T::throws(function () use ($wolvy, $top) { $wolvy->folders->update($top->id, ['parent_id' => $top->id]); }, ConflictException::class, '409 folder_loop', function ($e) {
        return $e->getErrorCode() === 'folder_loop';
    });
    $sub = $wolvy->folders->list(['parent_id' => $fx['folder_ids']['course_a']]);
    T::same(array_map(function ($f) { return $f->name; }, $sub->getData()), ['Module 1'], 'GET /v1/folders?parent_id=');
    $count = 0;
    foreach ($wolvy->folders->listAll(['limit' => 2]) as $folder) {
        $count++;
    }
    T::ok($count >= 6, "listAll(limit 2) walks every folder ({$count})");
    $wolvy->folders->delete($renamed->id);
    T::throws(function () use ($wolvy, $renamed) { $wolvy->folders->retrieve($renamed->id); }, NotFoundException::class, 'DELETE /v1/folders/{id}');
});

T::run('videos: update, bulk, ingest, delete', function () use ($wolvy, $V, $fx, $MISSING, &$state) {
    $moved = $wolvy->videos->update($V[1], ['title' => 'Algebra 2 (revised)', 'folder_id' => null]);
    T::ok($moved->title === 'Algebra 2 (revised)' && $moved->folder_id === null, 'PATCH /v1/videos/{id}: title, and folder_id null = root');
    $back = $wolvy->videos->update($V[1], ['folder_id' => $fx['folder_ids']['course_a']]);
    T::same($back->folder_id, $fx['folder_ids']['course_a'], 'moved back');
    T::throws(function () use ($wolvy, $V) { $wolvy->videos->update($V[1], []); }, InvalidRequestException::class, '400 no_fields for an empty PATCH', function ($e) {
        return $e->getErrorCode() === 'no_fields';
    });

    $bulk = $wolvy->videos->bulk(['action' => 'move', 'ids' => [$V[6], $MISSING], 'folder_id' => $fx['folder_ids']['module_1']]);
    T::ok($bulk instanceof Model\BulkResult && $bulk->done === 1 && $bulk->failed === 1, 'POST /v1/videos/bulk move: one done, one failed');
    T::same($bulk->results[1]->error->code, 'video_not_found', 'the failed result carries its code');
    $wolvy->videos->bulk(['action' => 'move', 'ids' => [$V[6]], 'folder_id' => null]);

    $video = $wolvy->videos->create(['source_url' => 'https://203.0.113.10/sdk-it/lesson.mp4', 'title' => 'SDK ingest', 'description' => 'Through the fake upstream']);
    T::ok($video instanceof Model\Video && $video->status === 'queued' && $video->original_url === 'https://203.0.113.10/sdk-it/lesson.mp4', 'POST /v1/videos (URL ingest): 202, queued');
    T::same($wolvy->getLastResponse()->getStatus(), 202, 'HTTP 202 Accepted');
    T::throws(function () use ($wolvy) { $wolvy->videos->create(['source_url' => 'https://203.0.113.10/a.mp4', 'encryption' => 'drm']); }, InvalidRequestException::class, '400 unknown_parameter for encryption (the plan decides)', function ($e) {
        return $e->getErrorCode() === 'unknown_parameter' && $e->getParam() === 'encryption';
    });
    T::throws(function () use ($wolvy) { $wolvy->videos->create(['source_url' => 'https://203.0.113.10/notes.txt']); }, UnprocessableEntityException::class, '422 unsupported_source_type for a text file', function ($e) {
        return $e->getErrorCode() === 'unsupported_source_type';
    });
    $wolvy->videos->delete($video->id);
    T::same($wolvy->getLastResponse()->getStatus(), 204, 'DELETE /v1/videos/{id}: 204 (media server call answered by the fake upstream)');
    T::throws(function () use ($wolvy, $video) { $wolvy->videos->retrieveStatus($video->id); }, NotFoundException::class, 'the deleted video is gone');
    $state['ingested'] = $video->id;
});

T::run('tags', function () use ($wolvy, $V, $ids) {
    $tags = $wolvy->tags->add($V[0], ['algebra', 'Week 1']);
    T::ok($tags instanceof Model\VideoTags && $tags->tags === ['algebra', 'Week 1'], 'POST /v1/videos/{id}/tags');
    T::same($wolvy->tags->replace($V[1], ['Algebra'])->tags, ['Algebra'], 'PUT /v1/videos/{id}/tags');
    T::same($ids($wolvy->videos->list(['tag' => 'ALGEBRA'])), [$V[0], $V[1]], 'GET /v1/videos?tag= ignores case');
    $list = $wolvy->tags->list();
    T::ok($list->first() instanceof Model\Tag && $list->first()->video_count === 2, 'GET /v1/tags, most used first');
    $wolvy->tags->remove($V[0], 'week 1');
    T::same($wolvy->videos->retrieve($V[0])->tags, ['algebra'], 'DELETE /v1/videos/{id}/tags/{tag} (URL-encoded, any case)');
    T::throws(function () use ($wolvy, $V) { $wolvy->tags->remove($V[0], 'Week 1'); }, NotFoundException::class, '404 tag_not_found', function ($e) {
        return $e->getErrorCode() === 'tag_not_found';
    });
    T::same($wolvy->tags->replace($V[1], [])->tags, [], 'PUT [] clears');
});

T::run('captions, chapters, moments', function () use ($wolvy, $V) {
    $captions = $wolvy->captions->list($V[0]);
    T::ok($captions->first() instanceof Model\Caption && $captions->first()->label === 'English', 'GET /v1/videos/{id}/captions');
    $after = $wolvy->captions->create($V[0], ['language_code' => 'ar', 'label' => 'العربية', 'content' => "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nمرحبا\n"]);
    T::same(array_map(function ($c) { return $c->language_code; }, $after->getData()), ['en', 'ar'], 'POST /v1/videos/{id}/captions answers the full list');
    T::throws(function () use ($wolvy, $V) { $wolvy->captions->create($V[0], ['language_code' => 'fr', 'content' => 'not a caption']); }, UnprocessableEntityException::class, '422 invalid_caption_file', function ($e) {
        return $e->getErrorCode() === 'invalid_caption_file';
    });
    $wolvy->captions->delete($V[0], 'ar');
    T::throws(function () use ($wolvy, $V) { $wolvy->captions->delete($V[0], 'ar'); }, NotFoundException::class, 'DELETE /v1/videos/{id}/captions/{language}, then 404 caption_not_found', function ($e) {
        return $e->getErrorCode() === 'caption_not_found';
    });

    $wolvy->chapters->create($V[0], ['title' => 'Intro', 'start' => 0, 'end' => 45]);
    $chapters = $wolvy->chapters->create($V[0], ['title' => 'Part 2', 'start' => 45, 'end' => 100]);
    T::ok(count($chapters) === 2 && $chapters->getData()[1] instanceof Model\Chapter && $chapters->getData()[1]->index === 1, 'POST /v1/videos/{id}/chapters');
    T::throws(function () use ($wolvy, $V) { $wolvy->chapters->create($V[0], ['title' => 'Overlap', 'start' => 30, 'end' => 50]); }, ConflictException::class, '409 chapter_overlap', function ($e) {
        return $e->getErrorCode() === 'chapter_overlap';
    });
    T::same($wolvy->chapters->update($V[0], 1, ['title' => 'Part two'])->getData()[1]->title, 'Part two', 'PATCH /v1/videos/{id}/chapters/{index}');
    $wolvy->chapters->delete($V[0], 0);
    $left = $wolvy->chapters->list($V[0]);
    T::ok(count($left) === 1 && $left->first()->title === 'Part two' && $left->first()->index === 0, 'DELETE …/chapters/0 renumbers the rest');
    T::throws(function () use ($wolvy, $V) { $wolvy->chapters->delete($V[0], 5); }, NotFoundException::class, '404 chapter_not_found');

    $moments = $wolvy->moments->create($V[0], ['label' => 'Demo', 'timestamp' => 120]);
    T::ok($moments->first() instanceof Model\Moment && $moments->first()->timestamp === 120, 'POST /v1/videos/{id}/moments');
    T::throws(function () use ($wolvy, $V) { $wolvy->moments->create($V[0], ['label' => 'Again', 'timestamp' => 120]); }, ConflictException::class, '409 moment_exists');
    T::same($wolvy->moments->update($V[0], '0', ['label' => 'Live demo'])->first()->label, 'Live demo', 'PATCH /v1/videos/{id}/moments/{index}');
    $wolvy->moments->delete($V[0], 0);
    T::same(count($wolvy->moments->list($V[0])), 0, 'DELETE /v1/videos/{id}/moments/{index}');
});

T::run('analytics and usage', function () use ($wolvy, $V) {
    $from = gmdate('Y-m-d', time() - 7 * 86400);
    $to = gmdate('Y-m-d');
    $overview = $wolvy->analytics->overview(['from' => $from, 'to' => new DateTime('now', new DateTimeZone('UTC'))]);
    T::ok($overview instanceof Model\AnalyticsOverview && $overview->from === $from && $overview->to === $to && $overview->video_count >= 7, 'GET /v1/analytics/overview (a DateTime for `to`)');
    T::ok($wolvy->analytics->daily(['from' => $from, 'to' => $to]) instanceof Collection, 'GET /v1/analytics/daily');
    T::ok($wolvy->analytics->countries() instanceof Collection, 'GET /v1/analytics/countries');
    try {
        $devices = $wolvy->analytics->devices(['from' => $from, 'to' => $to], ['max_retries' => 0]);
        T::ok($devices instanceof Model\AnalyticsDevices && is_array($devices->browsers), 'GET /v1/analytics/devices');
    } catch (ServerException $e) {
        T::same($e->getErrorCode(), 'service_unavailable', 'GET /v1/analytics/devices: 503 until the viewer-analytics cron has run');
    }
    $va = $wolvy->analytics->video($V[0]);
    T::ok($va instanceof Model\VideoAnalytics && $va->sessions === 3 && $va->watch_time === 1020, 'GET /v1/videos/{id}/analytics: 3 played sessions, 1020 s', json_encode($va->toArray()));
    T::throws(function () use ($wolvy, $to) { $wolvy->analytics->overview(['from' => $to, 'to' => '2020-01-01']); }, InvalidRequestException::class, '400 when from is after to');

    $usage = $wolvy->usage->retrieve();
    T::ok($usage instanceof Model\Usage && $usage->storage->limit_bytes === 536870912000 && $usage->storage->used_bytes === 96636764160, 'GET /v1/usage (bytes)');
    T::ok($wolvy->usage->history(['from' => $from]) instanceof Collection, 'GET /v1/usage/history');
    $plan = $wolvy->usage->retrievePlan();
    T::ok($plan instanceof Model\Plan && $plan->name === 'DRM Pro' && $plan->protection === 'drm', 'GET /v1/plan');
});

T::run('sessions, viewers, security events', function () use ($wolvy, $fx, $V, $ids) {
    $sessions = $wolvy->sessions->list();
    T::ok(count($sessions) === 3 && $sessions->first() instanceof Model\ViewerSession && $sessions->first()->viewer_verified === true, 'GET /v1/sessions: the three played sessions of this account');
    T::same($ids($wolvy->sessions->list(['viewer_id' => 'user-8813'])), [$fx['session_ids'][1], $fx['session_ids'][0]], 'filter viewer_id, newest first');
    T::same(count(iterator_to_array($wolvy->sessions->listAll(['limit' => 1]), false)), 3, 'listAll(limit 1): three pages');
    T::same($ids($wolvy->sessions->listForVideo($V[0], ['country' => 'sa'])), [$fx['session_ids'][2]], 'GET /v1/videos/{id}/sessions?country=sa (upper-cased by the API)');
    T::same(count(iterator_to_array($wolvy->sessions->listAllForVideo($V[0], ['limit' => 2]), false)), 3, 'listAllForVideo()');
    T::same($wolvy->sessions->retrieve($fx['session_ids'][3])->watch_seconds, 0, 'GET /v1/sessions/{id} returns one that never played');
    T::throws(function () use ($wolvy) { $wolvy->sessions->retrieve('nope'); }, NotFoundException::class, '404 session_not_found', function ($e) {
        return $e->getErrorCode() === 'session_not_found';
    });

    try {
        $viewers = $wolvy->viewers->list([], ['max_retries' => 0]);
        T::ok($viewers instanceof Collection, 'GET /v1/viewers (' . count($viewers) . ' rows before the hourly rebuild)');
        T::ok($wolvy->viewers->listSharing([], ['max_retries' => 0]) instanceof Collection, 'GET /v1/analytics/sharing');
        T::ok(is_array(iterator_to_array($wolvy->viewers->listSharingAll(['grade' => 'critical'], ['max_retries' => 0]), false)), 'listSharingAll() walks the report');
        try {
            $viewer = $wolvy->viewers->retrieve('user-8813', [], ['max_retries' => 0]);
            T::ok($viewer instanceof Model\Viewer, 'GET /v1/viewers/{viewer_id}');
        } catch (NotFoundException $e) {
            T::same($e->getErrorCode(), 'viewer_not_found', 'GET /v1/viewers/{viewer_id}: 404 viewer_not_found until the hourly rebuild');
        }
    } catch (ServerException $e) {
        T::same($e->getErrorCode(), 'service_unavailable', 'viewer routes: 503 "not available yet" on this database');
    }

    $events = $wolvy->securityEvents->list();
    T::ok(count($events) === 1 && $events->first() instanceof Model\SecurityEvent && $events->first()->details->had_vt === false, 'GET /v1/security-events: only this account\'s, details decoded');
    T::same(iterator_to_array($wolvy->securityEvents->listAll(['severity' => 'critical']), false), [], 'filter severity=critical: none');
    T::throws(function () use ($wolvy) { $wolvy->securityEvents->list(['event_type' => 'bogus']); }, InvalidRequestException::class, '400 for an unknown event_type');
});

T::section('Integration: a real webhook delivery');

T::run('POST /v1/webhook-endpoints/{id}/test, delivered by the API\'s worker, verified', function () use ($wolvy, &$state) {
    $endpoint = $state['endpoint'];
    if ($endpoint === null) {
        T::ok(false, 'an endpoint was created earlier');
        return;
    }
    $delivery = $wolvy->webhookEndpoints->sendTest($endpoint->id);
    T::ok($delivery instanceof Model\WebhookDelivery && $delivery->status === 'pending' && $delivery->event_type === 'webhook.test', 'sendTest() queues a pending webhook.test delivery');
    $pending = $wolvy->webhookDeliveries->list(['endpoint_id' => $endpoint->id, 'status' => 'pending']);
    T::ok(in_array($delivery->id, array_map(function ($d) { return $d->id; }, $pending->getData()), true), 'GET /v1/webhook-deliveries?status=pending lists it');

    $worker = getenv('WOLVY_IT_WEBHOOK_WORKER');
    $log = getenv('WOLVY_IT_UPSTREAM_LOG');
    if (!$worker || !$log) {
        echo '  (skipped: set WOLVY_IT_WEBHOOK_WORKER and WOLVY_IT_UPSTREAM_LOG for the real delivery)', PHP_EOL;
        return;
    }
    exec($worker . ' 2>&1', $output, $exit);
    T::same($exit, 0, 'the API\'s workers/webhook_dispatch.php ran');

    $received = [];
    foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) && $entry['path'] === '/hooks/sdk-it' && isset($entry['headers']['wolvy-delivery-id'])) {
            $received[(int) $entry['headers']['wolvy-delivery-id']] = $entry;
        }
    }
    T::ok(isset($received[$delivery->id]), 'the receiver got the test delivery (id ' . $delivery->id . ')');
    if (!isset($received[$delivery->id])) {
        return;
    }
    $hit = $received[$delivery->id];
    $raw = base64_decode($hit['body']);
    $header = $hit['headers']['wolvy-signature'];
    T::ok(preg_match('/^t=\d+,v1=[0-9a-f]{64}$/', $header) === 1 && $hit['headers']['user-agent'] === 'Wolvy-Webhooks/1.0', 'Wolvy-Signature: t=…,v1=… from Wolvy-Webhooks/1.0');

    $event = Wolvy::verifyWebhook($raw, $header, (string) $endpoint->secret);
    T::ok($event instanceof Model\WebhookEvent && $event->isTest() && $event->type === $hit['headers']['wolvy-event-type'], 'verifyWebhook() accepts the real delivery: webhook.test');
    T::ok(preg_match('/^evt_[0-9a-f]{20}$/', $event->getId()) === 1 && strpos((string) $event->data->message, 'verify this signature') !== false, 'event id evt_… and the test message');
    $client = new Client(null, ['webhook_secret' => (string) $endpoint->secret]);
    T::same($client->verifyWebhook($raw, $header)->getId(), $event->getId(), '$client->verifyWebhook() with the webhook_secret option');
    T::throws(function () use ($raw, $header, $endpoint) { Wolvy::verifyWebhook($raw . ' ', $header, (string) $endpoint->secret); }, SignatureVerificationException::class, 'the same delivery with one byte added is refused');
    T::throws(function () use ($raw, $header) { Wolvy::verifyWebhook($raw, $header, 'whsec_' . str_repeat('0', 48)); }, SignatureVerificationException::class, 'another endpoint\'s secret is refused');
    $t = (int) substr($header, 2, strpos($header, ',') - 2);
    T::throws(function () use ($raw, $header, $endpoint, $t) { Wolvy::verifyWebhook($raw, $header, (string) $endpoint->secret, 300, $t + 301); }, SignatureVerificationException::class, 'replayed 301 s later it is refused');

    $deleted = null;
    foreach ($received as $entry) {
        if ($entry['headers']['wolvy-event-type'] === 'video.deleted') {
            $deleted = $entry;
        }
    }
    T::ok($deleted !== null, 'the video.deleted event of the ingested video was delivered too');
    if ($deleted !== null) {
        $event = Wolvy::verifyWebhook(base64_decode($deleted['body']), $deleted['headers']['wolvy-signature'], (string) $endpoint->secret);
        T::ok($event->type === Model\WebhookEvent::VIDEO_DELETED && $event->data instanceof Model\Video && $event->data->id === $state['ingested'], 'verified: video.deleted for the deleted video');
    }

    $delivered = $wolvy->webhookDeliveries->list(['endpoint_id' => $endpoint->id, 'status' => 'delivered']);
    $codes = [];
    foreach ($wolvy->webhookDeliveries->listAll(['endpoint_id' => $endpoint->id, 'limit' => 1]) as $d) {
        $codes[$d->id] = [$d->status, $d->response_code];
    }
    T::ok(isset($codes[$delivery->id]) && $codes[$delivery->id] === ['delivered', 200], 'GET /v1/webhook-deliveries: the test delivery is delivered, HTTP 200 (listAll, one per page)');
    T::ok(count($delivered) >= 2, 'status=delivered lists both deliveries');
});
