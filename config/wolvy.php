<?php

/*
 * Wolvy (wolvy/wolvy-php) for Laravel. Publish with:
 *
 *     php artisan vendor:publish --tag=wolvy-config
 *
 * Keep the values in .env; never commit them.
 */

return [

    // Developer API key, wv_live_… (dashboard: Settings → API keys). Server side only.
    'api_key' => env('WOLVY_API_KEY'),

    // The account's viewer signing secret (dashboard: Settings → Watermark → Signing secret), for
    // Wolvy::signViewerToken() without an API call.
    'viewer_sign_secret' => env('WOLVY_VIEWER_SIGN_SECRET'),

    // The whsec_… secret of your webhook endpoint, for Wolvy::verifyWebhook() and the
    // Wolvy\Laravel\Middleware\VerifyWebhookSignature middleware.
    'webhook_secret' => env('WOLVY_WEBHOOK_SECRET'),

    // Your account id (GET /v1/account → id), for Wolvy::embedUrl(). Read from the API once when unset.
    'account_id' => env('WOLVY_ACCOUNT_ID'),

    'base_url' => env('WOLVY_API_BASE', 'https://api.wolvy.net/v1'),

    'embed_base_url' => env('WOLVY_EMBED_BASE', 'https://embed.wolvy.stream'),

    // Seconds.
    'timeout' => env('WOLVY_TIMEOUT', 80),

    'connect_timeout' => env('WOLVY_CONNECT_TIMEOUT', 10),

    // Retries after a network error, a 5xx, a 429 or a 409 request_in_progress.
    'max_retries' => env('WOLVY_MAX_RETRIES', 2),

    // Longest Retry-After honoured, in seconds; a 429 asking for longer is thrown at once.
    'max_retry_delay' => env('WOLVY_MAX_RETRY_DELAY', 60),

];
