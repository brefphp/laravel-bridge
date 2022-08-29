<?php

namespace CacheWerk\BrefLaravelBridge;

use Aws\Ssm\SsmClient;

class Secrets
{
    /**
     * Inject AWS SSM parameters into environment.
     *
     * @param  string  $path
     * @param  array  $parameters
     * @return void
     */
    public static function injectIntoEnvironment(string $path, array $parameters)
    {
        $ssm = new SsmClient([
            'version' => 'latest',
            'region' => $_ENV['AWS_REGION'] ?? $_ENV['AWS_DEFAULT_REGION'],
        ]);

        $response = $ssm->getParameters([
            'Names' => array_map(fn ($name) => trim($path) . trim($name), $parameters),
            'WithDecryption' => true,
        ]);

        $injected = [];

        foreach ($response['Parameters'] ?? [] as $secret) {
            $key = trim(strrchr($secret['Name'], '/'), '/');
            $injected[] = isset($_ENV[$key]) ? "{$key} (overwritten)" : $key;
            $_ENV[$key] = $secret['Value'];
        }

        if (! empty($injected)) {
            fwrite(STDERR, 'Injected runtime secrets: ' . implode(', ', $injected) . PHP_EOL);
        }
    }
}
