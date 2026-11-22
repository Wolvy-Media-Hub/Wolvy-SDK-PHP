<?php

namespace Wolvy\Laravel;

use Illuminate\Support\ServiceProvider;
use Wolvy\Client;

/**
 * Registers a shared Wolvy\Client configured from config/wolvy.php (environment variables
 * WOLVY_API_KEY, WOLVY_VIEWER_SIGN_SECRET, WOLVY_WEBHOOK_SECRET, …). Auto-discovered through
 * composer.json `extra.laravel`. Plain PHP never loads this class, so Laravel is not required.
 *
 *     public function show(\Wolvy\Client $wolvy) { … }      // injected
 *     \Wolvy::signViewerToken((string) auth()->id(), 3600, $videoId); // facade
 */
class WolvyServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(self::configFile(), 'wolvy');

        $this->app->singleton(Client::class, static function ($app) {
            return self::makeClient((array) $app['config']->get('wolvy', []));
        });
        $this->app->alias(Client::class, 'wolvy');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([self::configFile() => $this->app->configPath('wolvy.php')], 'wolvy-config');
        }
    }

    /**
     * A client from the `wolvy` config array (strings from .env are cast; empty values ignored).
     *
     * @param array<string,mixed> $config
     */
    public static function makeClient(array $config): Client
    {
        $options = [];
        $casts = [
            'base_url'           => 'string',
            'embed_base_url'     => 'string',
            'viewer_sign_secret' => 'string',
            'webhook_secret'     => 'string',
            'account_id'         => 'string',
            'timeout'            => 'float',
            'connect_timeout'    => 'float',
            'max_retries'        => 'int',
            'max_retry_delay'    => 'float',
        ];
        foreach ($casts as $key => $type) {
            if (!isset($config[$key]) || $config[$key] === '') {
                continue;
            }
            $value = $config[$key];
            if ($type === 'int') {
                $value = (int) $value;
            } elseif ($type === 'float') {
                $value = (float) $value;
            } else {
                $value = (string) $value;
            }
            $options[$key] = $value;
        }

        $apiKey = isset($config['api_key']) && $config['api_key'] !== '' ? (string) $config['api_key'] : null;

        return new Client($apiKey, $options);
    }

    public static function configFile(): string
    {
        return dirname(__DIR__, 2) . '/config/wolvy.php';
    }
}
