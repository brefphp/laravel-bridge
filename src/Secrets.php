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

        foreach ($response['Parameters'] ?? [] as $secret) {
            $key = trim(strrchr($secret['Name'], '/'), '/');

            fwrite(STDERR, sprintf(
                '%s: %s' . PHP_EOL,
                isset($_ENV[$key]) ? 'Overwriting runtime secret' : 'Injecting runtime secret',
                $key
            ));

            $_ENV[$key] = $secret['Value'];
        }
    }
}
