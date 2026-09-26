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
    | Jobs Logging
    |--------------------------------------------------------------------------
    |
    | Here you can disable detailed logging of every job execution.
    |
    */

    'log_jobs' => true,

    /*
    |--------------------------------------------------------------------------
    | Stack Name
    |--------------------------------------------------------------------------
    |
    | Name of the CloudFormation stack of the application: Bref sets the
    | `BREF_STACK_NAME` environment variable when deploying with serverless.yml.
    | Null in local development. For example, Lift names SQS queues
    | `<stack>-<construct>`: https://bref.sh/docs/laravel/queues#multiple-queues
    |
    */

    'stack_name' => env('BREF_STACK_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Presigned Uploads
    |--------------------------------------------------------------------------
    |
    | Lambda limits request bodies to a few megabytes. To upload larger files,
    | the browser asks this route for a presigned S3 URL and uploads the file
    | directly to S3 under a temporary prefix. The application then validates
    | the key with the `Bref\LaravelBridge\Upload\UploadedToS3` rule and copies
    | the file to its final location with `Storage::copy()`.
    |
    | The route is protected by the `uploadFiles` gate, which you must define
    | in your application (for example in `AppServiceProvider::boot()`). Uploads are
    | stored under `{prefix}/{user id}/` so that a user cannot reference
    | another user's upload.
    |
    */

    'uploads' => [
        // Route that returns presigned upload URLs. Set to null to disable the feature.
        'route' => '/signed-upload-url',
        // To allow uploads from guests, remove `auth` and accept a nullable user in the `uploadFiles` gate
        'middleware' => ['web', 'auth'],
        // Rate limit for signing requests: e.g. '60,1' (60 per minute), a named Laravel limiter, or null to disable
        'throttle' => null,
        // Disk used for uploads (null = default disk). Must be an S3 disk.
        'disk' => null,
        // S3 prefix of temporary uploads. Configure an S3 lifecycle rule to expire this prefix.
        'prefix' => 'tmp',
        // Validity of presigned URLs, in minutes
        'expires' => 5,
        // Maximum size in bytes, checked by the validation rule (null = no limit)
        'max_size' => 50 * 1024 * 1024, // 50 MB
    ],

];
