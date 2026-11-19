# Changelog

All notable changes to `wolvy/wolvy-php`. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## 1.0.1 (2026-11-19)

### Changed

- Package author is now "Wolvy" (was the company's legal name). No code change; the
  `User-Agent` reports `wolvy-php/1.0.1`.

## 1.0.0 (2026-11-19)

First release, for the Wolvy Developer API v1 as of November 2026.

### Added

- `Wolvy\Client` with one method for each of the 54 documented endpoints, grouped by resource:
  `health`, `account`, `videos`, `folders`, `tags`, `captions`, `chapters`, `moments`,
  `playback`, `analytics`, `usage`, `sessions`, `viewers`, `securityEvents`, `webhookEndpoints`,
  `webhookDeliveries`; and `request()` for routes not wrapped yet.
- Typed, read-only results (`Wolvy\Model\*`, chosen by the API's `object` field) and
  `Wolvy\Collection` for lists, with `nextPage()`, `autoPagingIterator()` and the services'
  `listAll()` generators.
- An `Idempotency-Key` on every `POST` except playback-token minting (which the docs advise to
  send without one, and which is safe to repeat), kept across retries and overridable per call.
  Bounded retries of network errors, 5xx, `429` (after `Retry-After` or `X-RateLimit-Reset`, up
  to `max_retry_delay`) and `409 request_in_progress`; token minting is retried too.
- Exceptions per HTTP status family (`InvalidRequestException`, `AuthenticationException`,
  `PermissionException`, `NotFoundException`, `ConflictException`,
  `UnprocessableEntityException`, `RateLimitException`, `ServerException`), all carrying the
  API's `code`, `type`, `param`, `request_id`, the status and the rate-limit numbers;
  `ConnectionException`, `SignatureVerificationException`, `InvalidArgumentException`.
- `Wolvy::signViewerToken()` (byte-identical to the API's tokens, same limits),
  `Wolvy::verifyWebhook()` (5-minute tolerance), `Wolvy::embedUrl()` and `Wolvy::deepLink()`;
  the same as functions in the `Wolvy` namespace and on a configured client.
- `$client->viewerSignSecretIsCurrent()`, the stored-secret check from the docs.
- Laravel: auto-discovered service provider (`WOLVY_API_KEY`, `WOLVY_VIEWER_SIGN_SECRET`,
  `WOLVY_WEBHOOK_SECRET`, …), the `Wolvy` facade, and the `VerifyWebhookSignature` middleware.
- `User-Agent: wolvy-php/1.0.0 PHP/<version>` on every request.
- PHP 7.4 or later; `ext-curl` and `ext-json` only.
