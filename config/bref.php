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
    | Use AWS credential tokens
    |--------------------------------------------------------------------------
    |
    | Bref fixes Laravel to use the AWS_SESSION_TOKEN environment variable
    | provided by AWS Lambda automatically (required for AWS credentials to work).
    | You should disable that only if you set your own AWS access keys manually
    | (which is not recommended in most cases).
    |
    */

    'use_session_token' => true,

];
