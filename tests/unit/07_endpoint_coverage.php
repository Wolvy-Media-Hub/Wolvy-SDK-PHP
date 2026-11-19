<?php
/**
 * One SDK method per Developer API endpoint. The table below is the reference's endpoint list
 * (Wolvy-Docs-UI/src/data/api.js, 2026-11-19): every entry is called on a fake transport and
 * must send its method to its path. When the docs and the API source are checked out beside
 * this repository (or WOLVY_DOCS_API_JS / WOLVY_API_ROUTES point at them), the table is also
 * compared with both, so a new endpoint fails this test until the SDK has a method for it.
 */

use Wolvy\Client;

T::section('Endpoint coverage');

$ENDPOINTS = [
    'get-health'              => ['GET', '/health', function (Client $c) { return $c->health->check(); }],
    'get-account'             => ['GET', '/v1/account', function (Client $c) { return $c->account->retrieve(); }],
    'get-player-settings'     => ['GET', '/v1/settings/player', function (Client $c) { return $c->account->retrievePlayerSettings(); }],
    'list-videos'             => ['GET', '/v1/videos', function (Client $c) { return $c->videos->list(); }],
    'create-video'            => ['POST', '/v1/videos', function (Client $c) { return $c->videos->create(['source_url' => 'https://x.test/a.mp4']); }],
    'get-video'               => ['GET', '/v1/videos/{id}', function (Client $c) { return $c->videos->retrieve('vid1'); }],
    'get-video-status'        => ['GET', '/v1/videos/{id}/status', function (Client $c) { return $c->videos->retrieveStatus('vid1'); }],
    'update-video'            => ['PATCH', '/v1/videos/{id}', function (Client $c) { return $c->videos->update('vid1', ['title' => 't']); }],
    'delete-video'            => ['DELETE', '/v1/videos/{id}', function (Client $c) { $c->videos->delete('vid1'); }],
    'bulk-videos'             => ['POST', '/v1/videos/bulk', function (Client $c) { return $c->videos->bulk(['action' => 'delete', 'ids' => ['vid1']]); }],
    'list-folders'            => ['GET', '/v1/folders', function (Client $c) { return $c->folders->list(); }],
    'create-folder'           => ['POST', '/v1/folders', function (Client $c) { return $c->folders->create(['name' => 'n']); }],
    'get-folder'              => ['GET', '/v1/folders/{id}', function (Client $c) { return $c->folders->retrieve(7); }],
    'update-folder'           => ['PATCH', '/v1/folders/{id}', function (Client $c) { return $c->folders->update(7, ['name' => 'n']); }],
    'delete-folder'           => ['DELETE', '/v1/folders/{id}', function (Client $c) { $c->folders->delete(7); }],
    'list-tags'               => ['GET', '/v1/tags', function (Client $c) { return $c->tags->list(); }],
    'replace-video-tags'      => ['PUT', '/v1/videos/{id}/tags', function (Client $c) { return $c->tags->replace('vid1', ['a']); }],
    'add-video-tags'          => ['POST', '/v1/videos/{id}/tags', function (Client $c) { return $c->tags->add('vid1', ['a']); }],
    'remove-video-tag'        => ['DELETE', '/v1/videos/{id}/tags/{tag}', function (Client $c) { $c->tags->remove('vid1', 'Week 1'); }],
    'list-captions'           => ['GET', '/v1/videos/{id}/captions', function (Client $c) { return $c->captions->list('vid1'); }],
    'add-caption'             => ['POST', '/v1/videos/{id}/captions', function (Client $c) { return $c->captions->create('vid1', ['language_code' => 'en']); }],
    'delete-caption'          => ['DELETE', '/v1/videos/{id}/captions/{language}', function (Client $c) { $c->captions->delete('vid1', 'en'); }],
    'list-chapters'           => ['GET', '/v1/videos/{id}/chapters', function (Client $c) { return $c->chapters->list('vid1'); }],
    'add-chapter'             => ['POST', '/v1/videos/{id}/chapters', function (Client $c) { return $c->chapters->create('vid1', ['title' => 't', 'start' => 0, 'end' => 1]); }],
    'update-chapter'          => ['PATCH', '/v1/videos/{id}/chapters/{index}', function (Client $c) { return $c->chapters->update('vid1', 0, ['title' => 't']); }],
    'delete-chapter'          => ['DELETE', '/v1/videos/{id}/chapters/{index}', function (Client $c) { $c->chapters->delete('vid1', 0); }],
    'list-moments'            => ['GET', '/v1/videos/{id}/moments', function (Client $c) { return $c->moments->list('vid1'); }],
    'add-moment'              => ['POST', '/v1/videos/{id}/moments', function (Client $c) { return $c->moments->create('vid1', ['label' => 'l', 'timestamp' => 1]); }],
    'update-moment'           => ['PATCH', '/v1/videos/{id}/moments/{index}', function (Client $c) { return $c->moments->update('vid1', 0, ['label' => 'l']); }],
    'delete-moment'           => ['DELETE', '/v1/videos/{id}/moments/{index}', function (Client $c) { $c->moments->delete('vid1', 0); }],
    'create-playback-token'   => ['POST', '/v1/videos/{id}/playback-tokens', function (Client $c) { return $c->playback->createToken('vid1', ['viewer_id' => 'u']); }],
    'get-embed'               => ['GET', '/v1/videos/{id}/embed', function (Client $c) { return $c->playback->retrieveEmbed('vid1'); }],
    'analytics-overview'      => ['GET', '/v1/analytics/overview', function (Client $c) { return $c->analytics->overview(); }],
    'analytics-daily'         => ['GET', '/v1/analytics/daily', function (Client $c) { return $c->analytics->daily(); }],
    'analytics-countries'     => ['GET', '/v1/analytics/countries', function (Client $c) { return $c->analytics->countries(); }],
    'analytics-devices'       => ['GET', '/v1/analytics/devices', function (Client $c) { return $c->analytics->devices(); }],
    'video-analytics'         => ['GET', '/v1/videos/{id}/analytics', function (Client $c) { return $c->analytics->video('vid1'); }],
    'get-usage'               => ['GET', '/v1/usage', function (Client $c) { return $c->usage->retrieve(); }],
    'usage-history'           => ['GET', '/v1/usage/history', function (Client $c) { return $c->usage->history(); }],
    'get-plan'                => ['GET', '/v1/plan', function (Client $c) { return $c->usage->retrievePlan(); }],
    'list-sessions'           => ['GET', '/v1/sessions', function (Client $c) { return $c->sessions->list(); }],
    'get-session'             => ['GET', '/v1/sessions/{session_id}', function (Client $c) { return $c->sessions->retrieve('sess1'); }],
    'video-sessions'          => ['GET', '/v1/videos/{id}/sessions', function (Client $c) { return $c->sessions->listForVideo('vid1'); }],
    'list-viewers'            => ['GET', '/v1/viewers', function (Client $c) { return $c->viewers->list(); }],
    'get-viewer'              => ['GET', '/v1/viewers/{viewer_id}', function (Client $c) { return $c->viewers->retrieve('user-8813'); }],
    'sharing-report'          => ['GET', '/v1/analytics/sharing', function (Client $c) { return $c->viewers->listSharing(); }],
    'list-security-events'    => ['GET', '/v1/security-events', function (Client $c) { return $c->securityEvents->list(); }],
    'list-webhook-endpoints'  => ['GET', '/v1/webhook-endpoints', function (Client $c) { return $c->webhookEndpoints->list(); }],
    'create-webhook-endpoint' => ['POST', '/v1/webhook-endpoints', function (Client $c) { return $c->webhookEndpoints->create(['url' => 'https://x.test', 'events' => ['video.ready']]); }],
    'get-webhook-endpoint'    => ['GET', '/v1/webhook-endpoints/{id}', function (Client $c) { return $c->webhookEndpoints->retrieve(3); }],
    'update-webhook-endpoint' => ['PATCH', '/v1/webhook-endpoints/{id}', function (Client $c) { return $c->webhookEndpoints->update(3, ['is_active' => true]); }],
    'delete-webhook-endpoint' => ['DELETE', '/v1/webhook-endpoints/{id}', function (Client $c) { $c->webhookEndpoints->delete(3); }],
    'test-webhook-endpoint'   => ['POST', '/v1/webhook-endpoints/{id}/test', function (Client $c) { return $c->webhookEndpoints->sendTest(3); }],
    'list-webhook-deliveries' => ['GET', '/v1/webhook-deliveries', function (Client $c) { return $c->webhookDeliveries->list(); }],
];

$SAMPLE = ['{id}' => null, '{tag}' => 'Week%201', '{language}' => 'en', '{index}' => '0', '{session_id}' => 'sess1', '{viewer_id}' => 'user-8813'];

T::run('every endpoint has a method that sends the right request', function () use ($ENDPOINTS, $SAMPLE) {
    T::same(count($ENDPOINTS), 54, '54 endpoints in the reference');
    $wrong = [];
    foreach ($ENDPOINTS as $id => [$method, $path, $call]) {
        $answer = $method === 'DELETE' ? FakeTransport::json(204, null) : FakeTransport::json(200, ['object' => 'x']);
        $transport = new FakeTransport($answer);
        $client = new Client('wv_live_' . str_repeat('A', 32), ['transport' => $transport]);
        $call($client);
        $sent = $transport->last();
        $expectedPath = $path;
        foreach ($SAMPLE as $placeholder => $value) {
            if ($placeholder === '{id}') {
                $value = strpos($path, '/folders/') !== false ? '7' : (strpos($path, '/webhook-endpoints/') !== false ? '3' : 'vid1');
            }
            $expectedPath = str_replace($placeholder, $value, $expectedPath);
        }
        $expectedUrl = 'https://api.wolvy.net' . $expectedPath;
        if ($sent['method'] !== $method || explode('?', $sent['url'])[0] !== $expectedUrl) {
            $wrong[] = "{$id}: sent {$sent['method']} {$sent['url']}, expected {$method} {$expectedUrl}";
        }
    }
    T::ok($wrong === [], 'each of the 54 sends its documented method and path', implode(PHP_EOL . '        ', $wrong));
});

T::run('the table matches the docs and the API source', function () use ($ENDPOINTS) {
    $mine = [];
    foreach ($ENDPOINTS as $id => [$method, $path]) {
        $mine[$id] = $method . ' ' . $path;
    }

    $apiJs = getenv('WOLVY_DOCS_API_JS') ?: dirname(__DIR__, 3) . '/Wolvy-Docs-UI/src/data/api.js';
    if (is_readable($apiJs)) {
        preg_match_all("/id: '([a-z-]+)',\s*method: '([A-Z]+)',\s*path: '([^']+)'/", (string) file_get_contents($apiJs), $m, PREG_SET_ORDER);
        $docs = [];
        foreach ($m as [, $id, $method, $path]) {
            $docs[$id] = $method . ' ' . $path;
        }
        ksort($docs);
        $sorted = $mine;
        ksort($sorted);
        T::same($sorted, $docs, 'identical to the endpoints of ' . realpath($apiJs));
    } else {
        echo '  (skipped: Wolvy-Docs-UI/src/data/api.js not found; set WOLVY_DOCS_API_JS)', PHP_EOL;
    }

    $routesFile = getenv('WOLVY_API_ROUTES') ?: dirname(__DIR__, 3) . '/Wolvy-APIs/app/routes.php';
    if (is_readable($routesFile)) {
        preg_match_all("/\\\$router->(get|post|patch|put|delete)\s*\('([^']+)'|\\\$router->open\('([A-Z]+)',\s*'([^']+)'/", (string) file_get_contents($routesFile), $m, PREG_SET_ORDER);
        $routes = [];
        foreach ($m as $match) {
            $routes[] = $match[1] !== '' ? strtoupper($match[1]) . ' ' . $match[2] : $match[3] . ' ' . $match[4];
        }
        sort($routes);
        $values = array_values($mine);
        sort($values);
        T::same($values, $routes, 'identical to the routes of ' . realpath($routesFile));
    } else {
        echo '  (skipped: Wolvy-APIs/app/routes.php not found; set WOLVY_API_ROUTES)', PHP_EOL;
    }
});
