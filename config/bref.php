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
    | Jobs Logging
    |--------------------------------------------------------------------------
    |
    | Here you can enable detailed logging of every job execution for debugging
    | purposes. Note, that enabling this, will result in a high volume of log
    | entries.
    |
    */

    'log_jobs' => false,
];
