<?php
/**
 * The Laravel service provider, facade and webhook middleware, on framework stubs; and that
 * plain PHP use never needs Laravel.
 */

use Wolvy\Client;
use Wolvy\Laravel\Facades\Wolvy as WolvyFacade;
use Wolvy\Laravel\Middleware\VerifyWebhookSignature;
use Wolvy\Laravel\WolvyServiceProvider;
use Wolvy\Model\WebhookEvent;
use Wolvy\Webhook;

T::section('Laravel');

T::run('plain PHP does not need Laravel', function () {
    $root = dirname(__DIR__, 2);
    $script = 'require ' . var_export($root . '/tests/bootstrap.php', true) . ';'
        . '$c = new Wolvy\\Client("wv_live_' . str_repeat('B', 32) . '", ["viewer_sign_secret" => "s", "account_id" => 42]);'
        . '$c->signViewerToken("u", 600, "abc"); $c->embedUrl("abc"); Wolvy\\Wolvy::verifyWebhook("{\\"id\\":1,\\"type\\":\\"x\\"}", Wolvy\\Webhook::sign("{\\"id\\":1,\\"type\\":\\"x\\"}", "w"), "w");'
        . 'foreach (get_declared_classes() as $k) { if (stripos($k, "Illuminate") === 0 || stripos($k, "Wolvy\\\\Laravel") === 0) { echo "loaded:", $k; exit(1); } }'
        . 'echo "clean";';
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
    T::same(trim((string) $output), 'clean', 'using the client and the helpers loads no Illuminate or Wolvy\\Laravel class');
});

require_once dirname(__DIR__) . '/support/laravel_stubs.php';

T::run('composer.json discovery', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
    T::same($composer['extra']['laravel']['providers'], [WolvyServiceProvider::class], 'extra.laravel.providers names the provider');
    T::same($composer['extra']['laravel']['aliases'], ['Wolvy' => WolvyFacade::class], 'extra.laravel.aliases registers the Wolvy facade');
    T::ok(class_exists($composer['extra']['laravel']['providers'][0]) && class_exists($composer['extra']['laravel']['aliases']['Wolvy']), 'both classes exist');
    T::same(array_keys($composer['require']), ['php', 'ext-curl', 'ext-json'], 'runtime requirements: PHP, ext-curl, ext-json only');
    T::same($composer['require']['php'], '>=7.4', 'PHP 7.4 or later');
    T::same($composer['license'], 'Apache-2.0', 'licence Apache-2.0');
});

T::run('provider from environment variables', function () {
    $secret = 'd80d15d09b7c0e56b4d787f6a212ed8bf8d53c761b6df521278f030cb12a7781';
    $env = [
        'WOLVY_API_KEY' => 'wv_live_' . str_repeat('C', 32),
        'WOLVY_VIEWER_SIGN_SECRET' => $secret,
        'WOLVY_WEBHOOK_SECRET' => 'whsec_' . str_repeat('1', 48),
        'WOLVY_ACCOUNT_ID' => '42',
        'WOLVY_MAX_RETRIES' => '3',
        'WOLVY_TIMEOUT' => '15',
    ];
    foreach ($env as $name => $value) {
        putenv("{$name}={$value}");
    }
    try {
        $app = new FakeLaravelApp();
        $provider = new WolvyServiceProvider($app);
        $provider->register();
        $provider->boot();

        $client = $app->make(Client::class);
        T::ok($client instanceof Client, 'the container builds a Wolvy\\Client');
        T::ok($app->make(Client::class) === $client && $app->make('wolvy') === $client, 'one shared instance, also under the alias "wolvy"');
        T::same($app['config']->get('wolvy')['api_key'], $env['WOLVY_API_KEY'], 'config wolvy.api_key comes from WOLVY_API_KEY');
        T::same(\Wolvy\ViewerToken::verify($client->signViewerToken('user-1', 600, 'vidA1'), $secret, 'vidA1'), 'user-1', 'signViewerToken() uses WOLVY_VIEWER_SIGN_SECRET');
        T::same($client->embedUrl('a1b2c3d4e5f60718293a'), 'https://embed.wolvy.stream/a1b2c3d4e5f60718293a/NDI=', 'embedUrl() uses WOLVY_ACCOUNT_ID, no API call');
        $body = '{"id":"evt_1","type":"webhook.test","data":{"object":"test"}}';
        T::same($client->verifyWebhook($body, Webhook::sign($body, $env['WOLVY_WEBHOOK_SECRET']))->id, 'evt_1', 'verifyWebhook() uses WOLVY_WEBHOOK_SECRET');
        T::same(\Illuminate\Support\ServiceProvider::$publishGroups['wolvy-config'], [WolvyServiceProvider::configFile() => '/app/config/wolvy.php'], 'boot() offers the config for vendor:publish --tag=wolvy-config');
        T::same($client->getBaseUrl(), 'https://api.wolvy.net/v1', 'the default base URL');

        \Illuminate\Support\Facades\Facade::$app = $app;
        T::same(\Wolvy\ViewerToken::verify(WolvyFacade::signViewerToken('user-2', 600, 'vidA1'), $secret, 'vidA1'), 'user-2', 'the facade reaches the shared client');
        T::ok(WolvyFacade::videos() instanceof \Wolvy\Service\VideosService, 'Wolvy::videos() through the facade');

        $middleware = new VerifyWebhookSignature($client);
        $good = new FakeLaravelRequest($body, ['Wolvy-Signature' => Webhook::sign($body, $env['WOLVY_WEBHOOK_SECRET'])]);
        $reached = false;
        $middleware->handle($good, function ($request) use (&$reached) {
            $reached = true;
            return 'next';
        });
        T::ok($reached && $good->attributes->get('wolvy_event') instanceof WebhookEvent, 'middleware: a valid delivery reaches the route, with the event attached');
        $bad = new FakeLaravelRequest($body . ' ', ['Wolvy-Signature' => Webhook::sign($body, $env['WOLVY_WEBHOOK_SECRET'])]);
        $reached = false;
        $response = $middleware->handle($bad, function () use (&$reached) {
            $reached = true;
        });
        T::ok(!$reached && $response->getStatusCode() === 400, 'middleware: a tampered delivery gets 400 and never reaches the route');
        $missing = new FakeLaravelRequest($body, []);
        T::same($middleware->handle($missing, function () { return 'next'; })->getStatusCode(), 400, 'middleware: no signature header gets 400');
    } finally {
        foreach (array_keys($env) as $name) {
            putenv($name);
        }
    }
});

T::run('makeClient() casts .env strings and skips empty values', function () {
    $client = WolvyServiceProvider::makeClient([
        'api_key' => '', 'viewer_sign_secret' => '', 'webhook_secret' => null, 'account_id' => '',
        'base_url' => 'http://127.0.0.1:8221/v1', 'timeout' => '5', 'connect_timeout' => '2', 'max_retries' => '0', 'max_retry_delay' => '10',
    ]);
    T::same($client->getBaseUrl(), 'http://127.0.0.1:8221/v1', 'WOLVY_API_BASE is honoured');
    T::throws(function () use ($client) { $client->signViewerToken('u', 600, 'vidA1'); }, \Wolvy\Exception\InvalidArgumentException::class, 'an empty secret stays unset');
    T::throws(function () use ($client) { $client->videos->list(); }, \Wolvy\Exception\InvalidArgumentException::class, 'an empty WOLVY_API_KEY: helpers only, API calls refused');
});
