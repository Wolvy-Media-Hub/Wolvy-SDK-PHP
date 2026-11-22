<?php

namespace Wolvy;

use Wolvy\Exception\ApiException;
use Wolvy\Exception\ConnectionException;
use Wolvy\Exception\InvalidArgumentException;
use Wolvy\Http\CurlTransport;
use Wolvy\Http\TransportInterface;
use Wolvy\Model\WebhookEvent;

/**
 * Client for the Wolvy Developer API v1 (https://api.wolvy.net/v1).
 *
 *     $wolvy = new \Wolvy\Client(getenv('WOLVY_API_KEY'));
 *     foreach ($wolvy->videos->listAll(['status' => 'ready']) as $video) {
 *         echo $video->title, "\n";
 *     }
 *
 * One service per API resource, as properties (or methods: `$wolvy->videos()`):
 *
 * @property-read Service\HealthService            $health
 * @property-read Service\AccountService           $account
 * @property-read Service\VideosService            $videos
 * @property-read Service\FoldersService           $folders
 * @property-read Service\TagsService              $tags
 * @property-read Service\CaptionsService          $captions
 * @property-read Service\ChaptersService          $chapters
 * @property-read Service\MomentsService           $moments
 * @property-read Service\PlaybackService          $playback
 * @property-read Service\AnalyticsService         $analytics
 * @property-read Service\UsageService             $usage
 * @property-read Service\SessionsService          $sessions
 * @property-read Service\ViewersService           $viewers
 * @property-read Service\SecurityEventsService    $securityEvents
 * @property-read Service\WebhookEndpointsService  $webhookEndpoints
 * @property-read Service\WebhookDeliveriesService $webhookDeliveries
 *
 * @method Service\HealthService            health()
 * @method Service\AccountService           account()
 * @method Service\VideosService            videos()
 * @method Service\FoldersService           folders()
 * @method Service\TagsService              tags()
 * @method Service\CaptionsService          captions()
 * @method Service\ChaptersService          chapters()
 * @method Service\MomentsService           moments()
 * @method Service\PlaybackService          playback()
 * @method Service\AnalyticsService         analytics()
 * @method Service\UsageService             usage()
 * @method Service\SessionsService          sessions()
 * @method Service\ViewersService           viewers()
 * @method Service\SecurityEventsService    securityEvents()
 * @method Service\WebhookEndpointsService  webhookEndpoints()
 * @method Service\WebhookDeliveriesService webhookDeliveries()
 */
class Client
{
    /** This SDK's version, sent in the User-Agent. */
    const VERSION = '1.1.0';

    const DEFAULT_BASE_URL = 'https://api.wolvy.net/v1';

    /** @var array<string,class-string<Service\AbstractService>> */
    const SERVICES = [
        'health'            => Service\HealthService::class,
        'account'           => Service\AccountService::class,
        'videos'            => Service\VideosService::class,
        'folders'           => Service\FoldersService::class,
        'tags'              => Service\TagsService::class,
        'captions'          => Service\CaptionsService::class,
        'chapters'          => Service\ChaptersService::class,
        'moments'           => Service\MomentsService::class,
        'playback'          => Service\PlaybackService::class,
        'analytics'         => Service\AnalyticsService::class,
        'usage'             => Service\UsageService::class,
        'sessions'          => Service\SessionsService::class,
        'viewers'           => Service\ViewersService::class,
        'securityEvents'    => Service\SecurityEventsService::class,
        'webhookEndpoints'  => Service\WebhookEndpointsService::class,
        'webhookDeliveries' => Service\WebhookDeliveriesService::class,
    ];

    /** @var array<string,mixed> */
    const DEFAULT_OPTIONS = [
        'base_url'           => self::DEFAULT_BASE_URL,
        'timeout'            => 80.0,
        'connect_timeout'    => 10.0,
        'max_retries'        => 2,
        'max_retry_delay'    => 60.0,
        'viewer_sign_secret' => null,
        'webhook_secret'     => null,
        'account_id'         => null,
        'embed_base_url'     => Embed::DEFAULT_BASE_URL,
        'ca_bundle'          => null,
        'transport'          => null,
        'sleep'              => null,
    ];

    /** Per-call options (the last argument of every service method). */
    const REQUEST_OPTIONS = ['idempotency_key', 'timeout', 'connect_timeout', 'max_retries', 'headers'];

    /** @var string|null */
    private $apiKey;

    /** @var array<string,mixed> */
    private $options;

    /** @var TransportInterface */
    private $transport;

    /** @var callable function (float $seconds): void */
    private $sleep;

    /** @var array<string,Service\AbstractService> */
    private $services = [];

    /** @var ApiResponse|null */
    private $lastResponse;

    /**
     * @param string|null         $apiKey  a `wv_live_…` key (dashboard: Settings → API keys). Only
     *                                     the API calls need it; the helpers work without one.
     * @param array<string,mixed> $options
     *   - base_url           (string)  default https://api.wolvy.net/v1
     *   - timeout            (float)   seconds for a whole request, default 80
     *   - connect_timeout    (float)   seconds to connect, default 10
     *   - max_retries        (int)     retries after a network error, a 5xx, a 429 or a 409
     *                                  request_in_progress; default 2 (0 turns retrying off)
     *   - max_retry_delay    (float)   longest wait honoured, in seconds, default 60. A 429 that
     *                                  asks for longer (the daily limit) is thrown at once.
     *   - viewer_sign_secret (string)  for signViewerToken()
     *   - webhook_secret     (string)  for verifyWebhook()
     *   - account_id         (int)     for embedUrl(); fetched once from GET /v1/account if unset
     *   - embed_base_url     (string)  default https://embed.wolvy.stream
     *   - ca_bundle          (string)  path to a CA bundle, when the system's is missing
     *   - transport          (TransportInterface) replaces the cURL transport
     *   - sleep              (callable) function (float $seconds): void, used between retries
     *
     * @throws InvalidArgumentException on an unknown option or a bad value
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        if ($apiKey === '') {
            $apiKey = null; // an unset environment variable: helpers still work, API calls say so
        }
        if ($apiKey !== null && preg_match('/\s/', $apiKey) === 1) {
            throw new InvalidArgumentException('The API key contains whitespace.');
        }
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULT_OPTIONS));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown client option(s): ' . implode(', ', $unknown) . '.');
        }
        $options = array_merge(self::DEFAULT_OPTIONS, $options);

        if (!is_string($options['base_url']) || preg_match('#^https?://[^/\s]+#i', $options['base_url']) !== 1) {
            throw new InvalidArgumentException('base_url must be an absolute http(s) URL, like https://api.wolvy.net/v1.');
        }
        $options['base_url'] = rtrim($options['base_url'], '/');
        foreach (['timeout', 'connect_timeout'] as $name) {
            if (!is_numeric($options[$name]) || (float) $options[$name] <= 0) {
                throw new InvalidArgumentException("{$name} must be a number of seconds above 0.");
            }
            $options[$name] = (float) $options[$name];
        }
        if (!is_int($options['max_retries']) || $options['max_retries'] < 0) {
            throw new InvalidArgumentException('max_retries must be an integer, 0 or more.');
        }
        if (!is_numeric($options['max_retry_delay']) || (float) $options['max_retry_delay'] < 0) {
            throw new InvalidArgumentException('max_retry_delay must be a number of seconds, 0 or more.');
        }
        $options['max_retry_delay'] = (float) $options['max_retry_delay'];
        foreach (['viewer_sign_secret', 'webhook_secret', 'ca_bundle'] as $name) {
            if ($options[$name] === '') {
                $options[$name] = null; // an unset environment variable reads as ''
            }
            if ($options[$name] !== null && !is_string($options[$name])) {
                throw new InvalidArgumentException("{$name} must be a string.");
            }
        }
        if ($options['account_id'] === '') {
            $options['account_id'] = null;
        }
        if ($options['account_id'] !== null) {
            if (!(is_int($options['account_id']) && $options['account_id'] > 0)
                && !(is_string($options['account_id']) && preg_match('/^[1-9]\d{0,18}$/', $options['account_id']) === 1)) {
                throw new InvalidArgumentException('account_id must be a positive integer (the `id` of GET /v1/account).');
            }
            $options['account_id'] = (int) $options['account_id'];
        }
        if ($options['transport'] !== null && !$options['transport'] instanceof TransportInterface) {
            throw new InvalidArgumentException('transport must implement Wolvy\\Http\\TransportInterface.');
        }
        if ($options['sleep'] !== null && !is_callable($options['sleep'])) {
            throw new InvalidArgumentException('sleep must be callable.');
        }

        $this->apiKey = $apiKey;
        $this->options = $options;
        $this->transport = $options['transport'] !== null ? $options['transport'] : new CurlTransport();
        $this->sleep = $options['sleep'] !== null ? $options['sleep'] : static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1000000));
            }
        };
    }

    /**
     * @return Service\AbstractService
     */
    public function __get(string $name)
    {
        if (!isset(self::SERVICES[$name])) {
            throw new \LogicException("Wolvy\\Client has no service '{$name}'.");
        }
        if (!isset($this->services[$name])) {
            $class = self::SERVICES[$name];
            $this->services[$name] = new $class($this);
        }
        return $this->services[$name];
    }

    public function __isset(string $name): bool
    {
        return isset(self::SERVICES[$name]);
    }

    /**
     * var_dump(), print_r() and Laravel's dd() show the configuration without the key or secrets.
     *
     * @return array<string,mixed>
     */
    public function __debugInfo()
    {
        $options = $this->options;
        foreach (['viewer_sign_secret', 'webhook_secret'] as $name) {
            if ($options[$name] !== null) {
                $options[$name] = '[redacted]';
            }
        }
        $options['transport'] = get_class($this->transport);
        $options['sleep'] = $options['sleep'] !== null ? 'callable' : null;
        return [
            'apiKey'  => $this->apiKey !== null ? substr($this->apiKey, 0, 12) . '…[redacted]' : null,
            'options' => $options,
        ];
    }

    /**
     * `$client->videos()` is `$client->videos` (what the Laravel facade calls).
     *
     * @param array<int,mixed> $arguments
     * @return Service\AbstractService
     */
    public function __call(string $name, array $arguments)
    {
        if (!isset(self::SERVICES[$name])) {
            throw new \BadMethodCallException("Call to undefined method Wolvy\\Client::{$name}()");
        }
        return $this->__get($name);
    }

    // ------------------------------------------------------------------------------------------
    // Helpers with the configured secrets
    // ------------------------------------------------------------------------------------------

    /**
     * Signs a viewer token with the `viewer_sign_secret` option. No API call.
     *
     * @param string|int $viewerId
     * @throws InvalidArgumentException when no secret is configured
     */
    public function signViewerToken($viewerId, int $ttlSeconds = ViewerToken::DEFAULT_TTL, ?string $video = null): string
    {
        return ViewerToken::sign($viewerId, $ttlSeconds, $this->requireOption('viewer_sign_secret'), null, $video);
    }

    /**
     * Verifies a webhook delivery with the `webhook_secret` option. No API call.
     *
     * @throws Exception\SignatureVerificationException
     * @throws InvalidArgumentException when no secret is configured
     */
    public function verifyWebhook(string $rawBody, ?string $signatureHeader, int $tolerance = Webhook::DEFAULT_TOLERANCE): WebhookEvent
    {
        return Webhook::verify($rawBody, $signatureHeader, $this->requireOption('webhook_secret'), $tolerance);
    }

    /**
     * The embed URL of a video for this account. Uses the `account_id` option; without it, reads
     * the id once from GET /v1/account (one API call per client).
     */
    public function embedUrl(string $videoId, ?string $viewerToken = null): string
    {
        return Embed::url($videoId, $this->accountId(), $viewerToken, (string) $this->options['embed_base_url']);
    }

    /**
     * True when the configured `viewer_sign_secret` is still the account's: mints a 60-second
     * token through the API and re-signs its payload locally (docs.wolvy.net/viewer-tokens/).
     * Run it after rotating the secret. One API call.
     */
    public function viewerSignSecretIsCurrent(string $videoId): bool
    {
        $secret = $this->requireOption('viewer_sign_secret');
        $token = $this->playback->createToken($videoId, ['viewer_id' => 'secret-check', 'ttl_seconds' => 60]);
        $parts = explode('.', (string) $token->vt);
        if (count($parts) !== 2) {
            return false;
        }
        $mine = rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0], $secret, true)), '+/', '-_'), '=');
        return hash_equals($parts[1], $mine);
    }

    /** The account id: the `account_id` option, else GET /v1/account (cached). */
    public function accountId(): int
    {
        if ($this->options['account_id'] === null) {
            $this->options['account_id'] = (int) $this->account->retrieve()->id;
        }
        return (int) $this->options['account_id'];
    }

    // ------------------------------------------------------------------------------------------
    // Requests
    // ------------------------------------------------------------------------------------------

    /**
     * Calls any endpoint and returns the typed result (null for a 204). For routes the SDK does
     * not wrap yet; the services cover every documented one.
     *
     * @param string              $method GET, POST, PATCH, PUT or DELETE
     * @param string              $path   relative to base_url, e.g. "/videos/a1b2…/status"
     * @param array<string,mixed> $params the query string for GET and DELETE, the JSON body otherwise
     * @param array<string,mixed> $opts   per-call options (see REQUEST_OPTIONS)
     *
     * @return WolvyObject|null
     */
    public function request(string $method, string $path, array $params = [], array $opts = [])
    {
        $method = strtoupper($method);
        $isRead = $method === 'GET' || $method === 'DELETE';
        $response = $this->send($method, $this->url($path), $isRead ? $params : [], $isRead ? null : $params, $opts);
        return self::toObject($response);
    }

    /**
     * The last answer received (on success; an ApiException carries its own).
     */
    public function getLastResponse(): ?ApiResponse
    {
        return $this->lastResponse;
    }

    public function getBaseUrl(): string
    {
        return (string) $this->options['base_url'];
    }

    /** @internal the API root (base_url without its /v1), where GET /health lives */
    public function rootUrl(): string
    {
        $base = (string) $this->options['base_url'];
        return preg_match('#/v1$#', $base) === 1 ? substr($base, 0, -3) : $base;
    }

    /** @internal */
    public function url(string $path): string
    {
        return $this->options['base_url'] . '/' . ltrim($path, '/');
    }

    /**
     * Sends one API call, with retries. @internal: the services and request() use it.
     *
     * @param array<string,mixed>      $query
     * @param array<string,mixed>|null $body  JSON body; null or [] sends none
     * @param array<string,mixed>      $opts
     * @param array<string,bool>       $flags how the route behaves, set by its service:
     *   - `authenticated` (default true): false only for GET /health, which takes no key;
     *   - `auto_idempotency_key` (default true): false for a POST that gets no key unless the
     *     caller passes one (playback-token minting);
     *   - `repeatable` (default false): true for a POST that is harmless to send twice, so it is
     *     retried after a network error or a 5xx even without a key.
     *
     * @throws ApiException
     * @throws ConnectionException
     * @throws InvalidArgumentException
     */
    public function send(string $method, string $url, array $query = [], ?array $body = null, array $opts = [], array $flags = []): ApiResponse
    {
        $unknown = array_diff(array_keys($opts), self::REQUEST_OPTIONS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown request option(s): ' . implode(', ', $unknown) . '.');
        }
        $authenticated = !isset($flags['authenticated']) || $flags['authenticated'] === true;
        $autoKey = !isset($flags['auto_idempotency_key']) || $flags['auto_idempotency_key'] === true;
        $repeatable = isset($flags['repeatable']) && $flags['repeatable'] === true;
        if ($authenticated && $this->apiKey === null) {
            throw new InvalidArgumentException('No API key configured: pass one to new Wolvy\\Client($apiKey).');
        }

        $queryString = self::buildQuery($query);
        if ($queryString !== '') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . $queryString;
        }

        $headers = [
            'Accept'     => 'application/json',
            'User-Agent' => self::userAgent(),
        ];
        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $payload = null;
        if ($body !== null && $body !== []) {
            if (array_keys($body) === range(0, count($body) - 1)) {
                throw new InvalidArgumentException('Request parameters must be an associative array (a JSON object).');
            }
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (!is_string($payload)) {
                throw new InvalidArgumentException('The request parameters could not be encoded as JSON: ' . json_last_error_msg() . '.');
            }
            $headers['Content-Type'] = 'application/json';
        }

        // A POST carries an Idempotency-Key, the same on each retry, so a retried create runs once.
        // The exception is a route that is harmless to repeat (playback-token minting): it gets a key
        // only when the caller asks for one.
        $idempotencyKey = null;
        if ($method === 'POST') {
            $given = array_key_exists('idempotency_key', $opts) ? $opts['idempotency_key'] : null;
            if ($given === true || ($given === null && $autoKey)) {
                $idempotencyKey = self::newIdempotencyKey();
            } elseif ($given !== false && $given !== null) {
                if (!is_string($given) || $given === '' || strlen($given) > 255) {
                    throw new InvalidArgumentException('idempotency_key must be a string of 1 to 255 characters (or false for none).');
                }
                $idempotencyKey = $given;
            }
            if ($idempotencyKey !== null) {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
        }

        if (isset($opts['headers'])) {
            if (!is_array($opts['headers'])) {
                throw new InvalidArgumentException('headers must be an array of name => value.');
            }
            foreach ($opts['headers'] as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }
        }

        $transportOptions = [
            'timeout'         => isset($opts['timeout']) ? (float) $opts['timeout'] : $this->options['timeout'],
            'connect_timeout' => isset($opts['connect_timeout']) ? (float) $opts['connect_timeout'] : $this->options['connect_timeout'],
            'ca_bundle'       => $this->options['ca_bundle'],
        ];
        $maxRetries = isset($opts['max_retries']) ? (int) $opts['max_retries'] : (int) $this->options['max_retries'];

        // A request that may have reached the API runs again on retry only when that is harmless:
        // reads, PUT/PATCH/DELETE, POSTs protected by their Idempotency-Key, and repeatable POSTs.
        $replaySafe = $method !== 'POST' || $idempotencyKey !== null || $repeatable;

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->transport->request($method, $url, $headers, $payload, $transportOptions);
            } catch (ConnectionException $e) {
                if ($replaySafe && $attempt <= $maxRetries) {
                    $this->pause($this->backoff($attempt));
                    continue;
                }
                throw $e;
            }

            $response = $response->withAttempts($attempt);
            if ($response->getStatus() < 400) {
                $this->lastResponse = $response;
                return $response;
            }

            $delay = $attempt <= $maxRetries ? $this->retryDelay($response, $attempt, $replaySafe, $idempotencyKey !== null) : null;
            if ($delay === null) {
                throw ApiException::fromResponse($response);
            }
            $this->pause($delay);
        }
    }

    /**
     * How long to wait before retrying this error answer, or null to throw it.
     */
    private function retryDelay(ApiResponse $response, int $attempt, bool $replaySafe, bool $hasIdempotencyKey): ?float
    {
        $status = $response->getStatus();
        $rateLimit = $response->getRateLimit();

        if ($status === 429) {
            // Refused before the handler ran, so always safe to send again, after the asked delay.
            $wait = null;
            if ($rateLimit !== null && $rateLimit->retryAfter !== null) {
                $wait = (float) $rateLimit->retryAfter;
            } elseif ($rateLimit !== null && $rateLimit->reset !== null) {
                $wait = (float) max(1, $rateLimit->reset - time());
            }
            if ($wait === null) {
                return $this->backoff($attempt);
            }
            return $wait <= $this->options['max_retry_delay'] ? $wait : null;
        }

        if ($status === 409) {
            // The first request with this key is still running: wait, then ask again with the same key.
            $json = $response->getJson();
            $code = isset($json['error']['code']) ? $json['error']['code'] : null;
            return ($hasIdempotencyKey && $code === 'request_in_progress') ? $this->backoff($attempt) : null;
        }

        if ($status >= 500 && $replaySafe) {
            if ($rateLimit !== null && $rateLimit->retryAfter !== null && $rateLimit->retryAfter <= $this->options['max_retry_delay']) {
                return (float) $rateLimit->retryAfter;
            }
            return $this->backoff($attempt);
        }

        return null;
    }

    /** Exponential backoff with jitter: about 0.5 s, 1 s, 2 s… capped at 8 s. */
    private function backoff(int $attempt): float
    {
        $base = min(8.0, 0.5 * pow(2, $attempt - 1));
        $jitter = 0.5 + (mt_rand(0, 1000) / 2000); // 0.5 to 1.0
        return min((float) $this->options['max_retry_delay'], $base * $jitter);
    }

    private function pause(float $seconds): void
    {
        call_user_func($this->sleep, $seconds);
    }

    /**
     * Decodes an answer into its typed object; null for an empty body (204).
     *
     * @internal
     * @return WolvyObject|null
     */
    public static function toObject(ApiResponse $response)
    {
        if ($response->getStatus() === 204 || $response->getBody() === '') {
            return null;
        }
        $json = $response->getJson();
        if ($json === null) {
            throw new ApiException('The Wolvy API answered with a body that is not JSON.', $response);
        }
        return WolvyObject::constructFrom($json);
    }

    /** `wolvy-php/<version> PHP/<php version>` */
    public static function userAgent(): string
    {
        return 'wolvy-php/' . self::VERSION . ' PHP/' . PHP_VERSION;
    }

    /** A random UUID v4. */
    public static function newIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /**
     * Query parameters: nulls are dropped, booleans become "true"/"false", dates are formatted
     * (`from`/`to` as YYYY-MM-DD; any other DateTimeInterface as RFC 3339 in UTC, which is what
     * the API compares against).
     *
     * @param array<string,mixed> $query
     * @internal
     */
    public static function buildQuery(array $query): string
    {
        $clean = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value instanceof \DateTimeInterface) {
                if ($key === 'from' || $key === 'to') {
                    $value = $value->format('Y-m-d');
                } else {
                    $utc = \DateTime::createFromFormat('U', (string) $value->getTimestamp());
                    $value = $utc === false ? $value->format(DATE_RFC3339) : $utc->format('Y-m-d\TH:i:s\Z');
                }
            } elseif (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException("Query parameter '{$key}' must be a string, a number, a boolean or a date.");
            }
            $clean[(string) $key] = (string) $value;
        }
        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return string */
    private function requireOption(string $name)
    {
        $value = $this->options[$name];
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("The client option '{$name}' is not set.");
        }
        return $value;
    }
}
