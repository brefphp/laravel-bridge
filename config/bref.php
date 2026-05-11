<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Servable Assets
    |--------------------------------------------------------------------------
    |
    | Here you can configure list of public assets that should be servable
    | from your application's domain instead of AWS CloudFront.
    | Read https://bref.sh/docs/use-cases/websites for a better solution.
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
    | Logging
    |--------------------------------------------------------------------------
    |
    | Lambda environments typically send logs to stderr rather than the local
    | filesystem. These settings control Bref's Lambda logging behavior.
    | Set either value to null to leave Laravel's logging configuration
    | untouched.
    |
    */

    'logging' => [
        'default' => 'stderr',
        'emergency_path' => 'php://stderr',
    ],

    /*
    |--------------------------------------------------------------------------
    | Jobs Logging
    |--------------------------------------------------------------------------
    |
    | Here you can disable detailed logging of every job execution.
    |
    */

    'log_jobs' => true,

];
