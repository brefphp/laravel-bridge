<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Servable Assets
    |--------------------------------------------------------------------------
    |
    | Here you can configure list of public assets that should be servable
    | from your application's domain instead of only being servable via
    | the public S3 "asset" bucket or the AWS CloudFront CDN network.
    |
    */

    'assets' => [
        // 'favicon.ico',
        // 'robots.txt',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared Log Context
    |--------------------------------------------------------------------------
    |
    | In order to make debugging a little easier, the Lambda `X-Request-ID`
    | value can be added to the shared log context automatically.
    |
    */

    'request_context' => false,

    /*
    |--------------------------------------------------------------------------
    | Log output at initialization
    |--------------------------------------------------------------------------
    |
    | Here you can choose whether to log the Laravel application initialization
    | process performed by Bref. These logs are output before the Laravel
    | application starts, so they are not formatted by `logging.default`.
    |
    */
    'log_init' => env('BREF_LARAVEL_BRIDGE_LOG_INIT', true),
];
