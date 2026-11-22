# Wolvy PHP SDK

The official PHP library for [Wolvy](https://wolvy.net/), secure DRM video hosting: a client for the
[Developer API](https://docs.wolvy.net/api/), plus the three things a server does without calling
it: **sign viewer tokens**, **verify webhooks** and **build embed URLs**. Works in plain PHP and
ships a Laravel service provider.

- PHP 7.4 or later, with `ext-curl` and `ext-json`. No other dependency.
- One method per API endpoint, typed results, cursor pagination helpers.
- Every `POST` that creates something carries an `Idempotency-Key`; network errors, 5xx, `429`
  and `409 request_in_progress` are retried a bounded number of times with the same key.
  Minting a playback token sends no key (it is safe to repeat) and is retried all the same.
- Errors are exceptions that carry the API's stable `code`, `type`, `param`, `request_id`, the HTTP
  status and the rate-limit numbers.
- Apache License 2.0.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Sign viewer tokens](#sign-viewer-tokens)
- [Verify webhooks](#verify-webhooks)
- [Embed URLs](#embed-urls)
- [Resources and methods](#resources-and-methods)
- [Results and pagination](#results-and-pagination)
- [Errors](#errors)
- [Retries, idempotency and rate limits](#retries-idempotency-and-rate-limits)
- [Laravel](#laravel)
- [Running the tests](#running-the-tests)

## Install

```bash
composer require wolvy/wolvy-php
```

## Quick start

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Wolvy\Client;
use Wolvy\Wolvy;

$wolvy = new Client(getenv('WOLVY_API_KEY'));            // wv_live_… (Settings → API)

// 1. List the videos that are ready, across every page.
foreach ($wolvy->videos->listAll(['status' => 'ready']) as $video) {
    echo $video->id, '  ', $video->title, PHP_EOL;
}

// 2. A signed viewer token for the person watching, minted by the API…
$token = $wolvy->playback->createToken($videoId, ['viewer_id' => 'user-8813', 'ttl_seconds' => 3600]);
echo $token->embed_url;                                   // https://embed.wolvy.stream/…/NDI=?vt=…

// …or signed locally, with no API call: the same string for the same input.
$vt = Wolvy::signViewerToken('user-8813', 3600, getenv('WOLVY_VIEWER_SIGN_SECRET'));

// 3. The embed URL for your page's iframe.
$src = Wolvy::embedUrl($videoId, 42, $vt);                // 42 = your account id (GET /v1/account → id)
```

Put the URL in the [recommended iframe](https://docs.wolvy.net/embedding/#iframe):

```html
<iframe src="<?= htmlspecialchars($src) ?>" title="Lesson 1"
        allow="autoplay; fullscreen; encrypted-media; picture-in-picture" allowfullscreen
        referrerpolicy="strict-origin-when-cross-origin"></iframe>
```

The API key and both secrets belong on your server only. Never send them to a browser or an app.

## Configuration

```php
$wolvy = new Wolvy\Client(getenv('WOLVY_API_KEY'), [
    'viewer_sign_secret' => getenv('WOLVY_VIEWER_SIGN_SECRET'), // for $wolvy->signViewerToken()
    'webhook_secret'     => getenv('WOLVY_WEBHOOK_SECRET'),     // for $wolvy->verifyWebhook()
    'account_id'         => 42,                                 // for $wolvy->embedUrl()
]);
```

| Option | Default | |
|---|---|---|
| `base_url` | `https://api.wolvy.net/v1` | The API. `GET /health` is called on the same host without `/v1`. |
| `timeout` | `80` | Seconds for a whole request. |
| `connect_timeout` | `10` | Seconds to connect. |
| `max_retries` | `2` | Retries after a network error, a 5xx, a `429` or a `409 request_in_progress`. `0` turns them off. |
| `max_retry_delay` | `60` | The longest wait honoured, in seconds. A `429` that asks for longer (the daily limit) is thrown at once. |
| `viewer_sign_secret` | none | The account's viewer signing secret (Settings → Watermark → Signing secret). |
| `webhook_secret` | none | The `whsec_…` secret of your webhook endpoint. |
| `account_id` | none | Your account id. Without it, `embedUrl()` reads it once from `GET /v1/account`. |
| `embed_base_url` | `https://embed.wolvy.stream` | The player host. |
| `ca_bundle` | system | Path to a CA bundle, for hosts whose PHP has none. |
| `transport` | cURL | Any `Wolvy\Http\TransportInterface`. |
| `sleep` | `usleep` | `function (float $seconds): void`, called between retries. |

The API key is optional: without one, the helpers work and API calls throw
`Wolvy\Exception\InvalidArgumentException`. Every request sends
`User-Agent: wolvy-php/<version> PHP/<version>`.

## Sign viewer tokens

A viewer token (`vt`) tells the player who is watching, in a form nobody can forge: it puts the
viewer's id on the watermark and on every session record, and it is what enforcement checks.
[Guide](https://docs.wolvy.net/viewer-tokens/).

```php
use Wolvy\Wolvy;

$vt = Wolvy::signViewerToken((string) $user->id, 3600, getenv('WOLVY_VIEWER_SIGN_SECRET'));

// The same, as a function or on a configured client:
$vt = \Wolvy\signViewerToken((string) $user->id, 3600, $secret);
$vt = $wolvy->signViewerToken((string) $user->id, 3600);

// Bound to one video: Wolvy plays this token for that video only.
$vt = $wolvy->signViewerToken((string) $user->id, 3600, 'a1b2c3d4e5f60718293a');
```

- **Byte-identical to the API.** For the same viewer id, lifetime and second,
  `signViewerToken()` returns exactly the `vt` of `POST /v1/videos/{id}/playback-tokens`, and it
  reproduces the [published test vector](https://docs.wolvy.net/viewer-tokens/#test-vectors). It
  applies the API's rules: the viewer id is trimmed, then 1 to 64 bytes of UTF-8; the lifetime is
  60 to 86,400 seconds (anything else throws `Wolvy\Exception\InvalidArgumentException`); the JSON
  is spelled as the API spells it (PHP's default `json_encode`, which escapes `/` and non-ASCII
  characters).
- **One token plays every video of the account** until it expires; the payload is only
  `{vid, exp}`.
- Sign **one fresh token per page view**, and send pages that carry one with
  `Cache-Control: private, no-store`.
- Use the secret as shown in the dashboard; do not hex-decode it.
- `Wolvy\ViewerToken::verify($vt, $secret)` checks a token the way Wolvy's player backend does,
  and `$wolvy->viewerSignSecretIsCurrent($videoId)` tells you, with one API call, whether your
  stored secret is still the account's (run it after a rotation).

## Verify webhooks

Wolvy signs every delivery with your endpoint's secret: `Wolvy-Signature: t=<unix>,v1=<hex>`,
an HMAC-SHA256 over `"<t>.<raw body>"`. [Guide](https://docs.wolvy.net/webhooks/).

```php
use Wolvy\Exception\SignatureVerificationException;
use Wolvy\Model\WebhookEvent;
use Wolvy\Wolvy;

$raw = file_get_contents('php://input');                 // the raw body, before any json_decode
$header = $_SERVER['HTTP_WOLVY_SIGNATURE'] ?? null;

try {
    $event = Wolvy::verifyWebhook($raw, $header, getenv('WOLVY_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

http_response_code(200);                                 // answer within 15 seconds…
if (already_processed($event->id)) {                     // …deliveries can repeat: dedupe on id
    return;
}
switch ($event->type) {
    case WebhookEvent::VIDEO_READY:
        publish_lesson($event->data->id);                // $event->data is a Wolvy\Model\Video
        break;
    case WebhookEvent::VIDEO_FAILED:
        alert_team($event->data->id, $event->data->error);
        break;
}
```

- `verifyWebhook($rawBody, $signatureHeader, $secret, $tolerance = 300)` returns a
  `Wolvy\Model\WebhookEvent` (`id`, `type`, `created_at`, `data`) or throws
  `SignatureVerificationException` when the header is missing or malformed, the timestamp is more
  than `$tolerance` seconds from your clock (either way), the signature does not match, or the
  body is not an event. `$tolerance = 0` turns the time check off, for re-checking a stored
  delivery.
- `data` is typed: a `Model\Video` for the video and caption events, `Model\SecurityEvent`,
  `Model\Payment`, `Model\UsageThreshold`.
- `WebhookEvent::SUBSCRIBABLE` lists the events an endpoint can subscribe to; `webhook.test`
  comes only from `$wolvy->webhookEndpoints->sendTest($id)`.
- `Wolvy\Webhook::sign($body, $secret)` builds a valid header, for testing your own receiver.

## Embed URLs

```php
Wolvy::embedUrl('a1b2c3d4e5f60718293a', 42);       // https://embed.wolvy.stream/a1b2c3d4e5f60718293a/NDI=
Wolvy::embedUrl('a1b2c3d4e5f60718293a', 42, $vt);  // …/NDI=?vt=<url-encoded token>
Wolvy::deepLink('a1b2c3d4e5f60718293a', 42, $vt);  // wolvy://play/a1b2c3d4e5f60718293a/NDI=/<token> (Wolvy Player app)
$wolvy->embedUrl($videoId, $vt);                   // with the client's account_id
```

The account id is the `id` of `GET /v1/account`, base64-encoded as text (`42` → `NDI=`), as in the
API's own `embed_url`. Opening an embed URL in the address bar shows a 403 page: the player checks
the page's Referer against your allowed domains.

## Resources and methods

Every method takes an optional last argument `$opts` ([per-call options](#per-call-options)).
`$params` are the API's documented body fields (for `POST`, `PATCH`, `PUT`) or query parameters
(for `GET`), as an associative array. Field and parameter details:
[API reference](https://docs.wolvy.net/api/).

| Service | Method | Endpoint | Returns |
|---|---|---|---|
| `health` | `check()` | `GET /health` (no key) | `Model\Health` |
| `account` | `retrieve()` | `GET /v1/account` | `Model\Account` |
| | `retrievePlayerSettings()` | `GET /v1/settings/player` | `Model\PlayerSettings` |
| `videos` | `list($params)` / `listAll($params)` | `GET /v1/videos` | `Collection` / `Generator` of `Model\Video` |
| | `create($params)` | `POST /v1/videos` (URL ingest) | `Model\Video` |
| | `retrieve($id)` | `GET /v1/videos/{id}` | `Model\Video` |
| | `retrieveStatus($id)` | `GET /v1/videos/{id}/status` | `Model\VideoStatus` |
| | `update($id, $params)` | `PATCH /v1/videos/{id}` | `Model\Video` |
| | `delete($id)` | `DELETE /v1/videos/{id}` | nothing |
| | `bulk($params)` | `POST /v1/videos/bulk` | `Model\BulkResult` |
| `folders` | `list($params)` / `listAll($params)` | `GET /v1/folders` | `Model\Folder` |
| | `create($params)` | `POST /v1/folders` | `Model\Folder` |
| | `retrieve($id)` | `GET /v1/folders/{id}` | `Model\Folder` |
| | `update($id, $params)` | `PATCH /v1/folders/{id}` | `Model\Folder` |
| | `delete($id)` | `DELETE /v1/folders/{id}` | nothing |
| `tags` | `list()` | `GET /v1/tags` | `Collection` of `Model\Tag` |
| | `replace($videoId, $tags)` | `PUT /v1/videos/{id}/tags` | `Model\VideoTags` |
| | `add($videoId, $tags)` | `POST /v1/videos/{id}/tags` | `Model\VideoTags` |
| | `remove($videoId, $tag)` | `DELETE /v1/videos/{id}/tags/{tag}` | nothing |
| `captions` | `list($videoId)` | `GET /v1/videos/{id}/captions` | `Collection` of `Model\Caption` |
| | `create($videoId, $params)` | `POST /v1/videos/{id}/captions` | `Collection` (the full list) |
| | `delete($videoId, $language)` | `DELETE /v1/videos/{id}/captions/{language}` | nothing |
| `chapters` | `list($videoId)` | `GET /v1/videos/{id}/chapters` | `Collection` of `Model\Chapter` |
| | `create($videoId, $params)` | `POST /v1/videos/{id}/chapters` | `Collection` (the full list) |
| | `update($videoId, $index, $params)` | `PATCH /v1/videos/{id}/chapters/{index}` | `Collection` |
| | `delete($videoId, $index)` | `DELETE /v1/videos/{id}/chapters/{index}` | nothing |
| `moments` | `list`, `create`, `update`, `delete` | `/v1/videos/{id}/moments[/{index}]` | as chapters, `Model\Moment` |
| `playback` | `createToken($videoId, $params)` | `POST /v1/videos/{id}/playback-tokens` | `Model\PlaybackToken` |
| | `retrieveEmbed($videoId)` | `GET /v1/videos/{id}/embed` | `Model\Embed` |
| `analytics` | `overview($params)` | `GET /v1/analytics/overview` | `Model\AnalyticsOverview` |
| | `daily($params)` | `GET /v1/analytics/daily` | `Collection` of `Model\AnalyticsDay` |
| | `countries($params)` | `GET /v1/analytics/countries` | `Collection` of `Model\AnalyticsCountry` |
| | `devices($params)` | `GET /v1/analytics/devices` | `Model\AnalyticsDevices` |
| | `video($videoId)` | `GET /v1/videos/{id}/analytics` | `Model\VideoAnalytics` |
| `usage` | `retrieve()` | `GET /v1/usage` | `Model\Usage` |
| | `history($params)` | `GET /v1/usage/history` | `Collection` of `Model\UsageDay` |
| | `retrievePlan()` | `GET /v1/plan` | `Model\Plan` |
| `sessions` | `list($params)` / `listAll($params)` | `GET /v1/sessions` | `Model\ViewerSession` |
| | `retrieve($sessionId)` | `GET /v1/sessions/{session_id}` | `Model\ViewerSession` |
| | `listForVideo($videoId, $params)` / `listAllForVideo(…)` | `GET /v1/videos/{id}/sessions` | `Model\ViewerSession` |
| `viewers` | `list($params)` / `listAll($params)` | `GET /v1/viewers` | `Model\Viewer` |
| | `retrieve($viewerId, $params)` | `GET /v1/viewers/{viewer_id}` | `Model\Viewer` |
| | `listSharing($params)` / `listSharingAll($params)` | `GET /v1/analytics/sharing` | `Model\ViewerSharing` |
| `securityEvents` | `list($params)` / `listAll($params)` | `GET /v1/security-events` | `Model\SecurityEvent` |
| `webhookEndpoints` | `list()` | `GET /v1/webhook-endpoints` | `Collection` of `Model\WebhookEndpoint` |
| | `create($params)` | `POST /v1/webhook-endpoints` | `Model\WebhookEndpoint` (with `secret`, once) |
| | `retrieve($id)` | `GET /v1/webhook-endpoints/{id}` | `Model\WebhookEndpoint` |
| | `update($id, $params)` | `PATCH /v1/webhook-endpoints/{id}` | `Model\WebhookEndpoint` |
| | `delete($id)` | `DELETE /v1/webhook-endpoints/{id}` | nothing |
| | `sendTest($id)` | `POST /v1/webhook-endpoints/{id}/test` | `Model\WebhookDelivery` |
| `webhookDeliveries` | `list($params)` / `listAll($params)` | `GET /v1/webhook-deliveries` | `Model\WebhookDelivery` |

Services are properties (`$wolvy->videos`) or methods (`$wolvy->videos()`). For a route this
version does not wrap yet, `$wolvy->request('GET', '/path', $params)` returns the typed result.

Query values may be `DateTimeInterface` objects: `from` and `to` are sent as `YYYY-MM-DD`, and
`created_after` / `created_before` as UTC timestamps (the API drops an offset rather than
converting it, so the SDK converts first). `null` values are left out of queries and kept in
bodies (`['folder_id' => null]` moves a video to the root).

A viewer id containing `/` cannot be read by path; `viewers->retrieve()` refuses it and points to
`viewers->list(['viewer_id' => …])`.

## Results and pagination

Results are read-only objects typed by the API's `object` field (`Wolvy\Model\Video`,
`Wolvy\Model\Folder`, …), all `Wolvy\WolvyObject`s. Fields read as properties or array keys;
nested objects are `WolvyObject`s too; a field the API did not send reads as `null`.

```php
$video = $wolvy->videos->retrieve($id);
$video->title;            $video['title'];
$video->captions[0]->language_code;
$video->isReady();        // also isPlayable(), isFailed()
$video->toArray();        // the decoded JSON, exactly
json_encode($video);      // the same JSON
```

Lists come back as a `Wolvy\Collection`: one page, newest first, with `has_more` and
`next_cursor`. Iterating a collection walks that page; to walk them all, let the SDK follow
`next_cursor` (with the same filters) as you go:

```php
$page = $wolvy->videos->list(['limit' => 100, 'status' => 'ready']);
count($page);             // items on this page
$page->hasMore();
$next = $page->nextPage(); // null after the last page

foreach ($page->autoPagingIterator() as $video) { … }      // this page and every following one
foreach ($wolvy->videos->listAll(['status' => 'ready']) as $video) { … }   // the same, lazily
```

`listAll()` sends nothing until the first item is asked for, so errors surface while iterating.
Lists the API does not paginate (captions, chapters, moments, tags, endpoints, analytics rows)
come back whole.

## Errors

Every exception implements `Wolvy\Exception\WolvyException`.

| Exception | When |
|---|---|
| `InvalidRequestException` | 400 (`invalid_parameter`, `missing_parameter`, `unknown_parameter`, `invalid_cursor`, `no_fields`, …) and 405 |
| `AuthenticationException` | 401: `missing_api_key`, `invalid_api_key`, `expired_api_key` |
| `PermissionException` | 403: `insufficient_scope`, `ip_not_allowed`, `account_inactive` |
| `NotFoundException` | 404: `video_not_found`, `folder_not_found`, …, and another account's resource |
| `ConflictException` | 409: `idempotency_key_reused`, `request_in_progress`, `chapter_overlap`, `moment_exists`, `folder_loop`, `folder_too_deep`, `not_available` |
| `UnprocessableEntityException` | 422: `no_active_plan`, `insufficient_storage`, `account_settings_missing`, `too_many_tags`, the source-URL codes, `invalid_caption_file` |
| `RateLimitException` | 429 `rate_limit_exceeded`, after the retries |
| `ServerException` | 500 `internal_error`, 503 `service_unavailable`, a proxy's 502/504, after the retries |
| `ApiException` | the base of all of the above; any other status |
| `ConnectionException` | no HTTP answer (DNS, TCP, TLS, timeout) after the retries; `isTimeout()` |
| `SignatureVerificationException` | a webhook that does not verify |
| `InvalidArgumentException` | a bad value caught before anything was sent |

```php
use Wolvy\Exception\ApiException;
use Wolvy\Exception\NotFoundException;

try {
    $video = $wolvy->videos->retrieve($id);
} catch (NotFoundException $e) {
    // gone, or another account's
} catch (ApiException $e) {
    error_log(sprintf('%s %s (%s) %s', $e->getHttpStatus(), $e->getErrorCode(), $e->getRequestId(), $e->getMessage()));
}
```

`ApiException` getters: `getErrorCode()` (the stable `code`, branch on it), `getErrorType()`,
`getParam()`, `getRequestId()` (quote it to support), `getHttpStatus()` (also `getCode()`),
`getRateLimit()`, `getRetryAfter()`, `getResponse()` (status, headers, raw body).

## Retries, idempotency and rate limits

- **Every `POST` carries an `Idempotency-Key`** (a UUID), the same on every retry of that call,
  so a retried create runs once: the API answers a repeat from its store
  (`$wolvy->getLastResponse()->isIdempotentReplay()`). The one exception is below.
- **Playback tokens are minted without a key.** The docs advise against one (a replay would hand
  back a token minted earlier), and the API would keep every mint for 24 hours. Minting has no
  side effect, so `createToken()` is still retried after a network error, a 5xx or a `429`; at
  worst a second token is minted and the first is never used. Pass `['idempotency_key' => …]`
  (or `true` for a generated one) only if you want a replayable mint.
- **Retried**, up to `max_retries` times: network errors and 5xx for `GET`, `PUT`, `PATCH`,
  `DELETE`, keyed `POST`s and token minting (backoff about 0.5 s, 1 s, 2 s… with jitter); `429`
  always (it is refused before any work), after `Retry-After` (or until `X-RateLimit-Reset`);
  `409 request_in_progress` (the first request with that key is still running).
- **Not retried**: other 4xx, a `429` asking for more than `max_retry_delay`, and a `POST` sent
  with `'idempotency_key' => false` after a network error or a 5xx.
- After a `ConnectionException` on a `POST`, retrying it yourself with the same key is safe: pass
  `['idempotency_key' => $key]`. A failed request gives its key back, so a corrected retry with
  the same key runs.
- The rate limit of the last answer: `$wolvy->getLastResponse()->getRateLimit()` (`limit`,
  `remaining`, `reset`, `dailyLimit`, `dailyRemaining`, `retryAfter`). Errors carry it too.

### Per-call options

```php
$wolvy->videos->create($params, [
    'idempotency_key' => 'import-2026-11-19-lesson-7', // false for none, true for a generated one
    'timeout'         => 120,
    'max_retries'     => 0,
    'headers'         => ['X-Debug' => '1'],
]);
```

## Laravel

The package registers itself (Laravel 8 or later) through package discovery: a shared
`Wolvy\Client` in the container (also as `wolvy`), the `Wolvy` facade, and the webhook middleware.
Plain PHP never loads these classes; Laravel is not a dependency.

`.env`:

```dotenv
WOLVY_API_KEY=wv_live_…
WOLVY_VIEWER_SIGN_SECRET=…        # Settings → Watermark → Signing secret
WOLVY_WEBHOOK_SECRET=whsec_…      # returned when the endpoint was created
WOLVY_ACCOUNT_ID=42               # optional: GET /v1/account → id
```

Optional: `WOLVY_API_BASE`, `WOLVY_EMBED_BASE`, `WOLVY_TIMEOUT`, `WOLVY_CONNECT_TIMEOUT`,
`WOLVY_MAX_RETRIES`, `WOLVY_MAX_RETRY_DELAY`. To edit the config file:
`php artisan vendor:publish --tag=wolvy-config`.

```php
use Wolvy\Client;
use Wolvy\Laravel\Facades\Wolvy;

class LessonController
{
    public function show(Client $wolvy, string $videoId)
    {
        $vt = $wolvy->signViewerToken((string) auth()->id(), 3600);
        return response()
            ->view('lesson', ['src' => $wolvy->embedUrl($videoId, $vt)])
            ->header('Cache-Control', 'private, no-store');
    }
}

Wolvy::videos()->list(['status' => 'ready']);    // the facade
```

Webhooks: the middleware verifies `Wolvy-Signature` with `WOLVY_WEBHOOK_SECRET`, answers 400 to a
delivery that does not verify, and hands the event to your controller:

```php
// routes/api.php (outside the CSRF-protected "web" group)
Route::post('/hooks/wolvy', WolvyWebhookController::class)
    ->middleware(\Wolvy\Laravel\Middleware\VerifyWebhookSignature::class);

// in the controller
$event = $request->attributes->get('wolvy_event');  // Wolvy\Model\WebhookEvent
```

## Running the tests

```bash
php tests/run.php                # unit tests: no network, no API, PHP 7.4 to 8.5
```

No PHPUnit: the runner has no dependencies, so the same suite runs on every PHP version. CI runs
it on PHP 7.4 to 8.4. When the Wolvy platform repositories are checked out beside this one, the
unit tests also compare with them: the player backend's own token verifier
(`Wolvy-Backend/viewer_token.php`), the API's own token minting
(`Wolvy-APIs/app/src/Wire/ViewerToken.php`), and the endpoint lists of the API reference
(`Wolvy-Docs-UI/src/data/api.js`) and of the API's routes; otherwise those checks use pinned copies
or are skipped.

The integration suite runs against a local copy of the Developer API and its database:

```bash
WOLVY_IT_FIXTURE=/path/fixture.json php tests/run.php integration
```

`tests/integration/suite.php` describes the fixture (keys, ids and secrets of a seeded test
account) and the optional variables for the real webhook delivery, which goes through
`tests/integration/fake_upstream.php`.

## Versioning

[Semantic versioning](https://semver.org/). Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## License

Apache License 2.0. See [LICENSE](LICENSE).
