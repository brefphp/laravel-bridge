<?php

namespace CacheWerk\BrefLaravelBridge;

use RuntimeException;
use Bref\Runtime\FileHandlerLocator;
use Psr\Container\ContainerInterface;
use Illuminate\Foundation\Application;

/**
 * This class resolves Lambda handlers.
 *
 * It extends the default Bref behavior (that resolves handlers from files)
 * to also resolve class handlers from the Laravel container.
 */
class HandlerResolver implements ContainerInterface
{
    private ?Application $laravelApp;
    private FileHandlerLocator $fileLocator;

    public function __construct()
    {
        // Bref's default handler resolver
        $this->fileLocator = new FileHandlerLocator;
        $this->laravelApp = null;
    }

    public function get(string $id)
    {
        // By default, we check if the handler is a file name (classic Bref behavior)
        if ($this->fileLocator->has($id)) {
            return $this->fileLocator->get($id);
        }

        // If not, we try to get the handler from the Laravel container
        return $this->laravelApp()->get($id);
    }

    public function has(string $id): bool
    {
        // By default, we check if the handler is a file name (classic Bref behavior)
        if ($this->fileLocator->has($id)) {
            return true;
        }

        // If not, we try to get the handler from the Laravel container
        return $this->laravelApp()->has($id);
    }

    /**
     * Create and return the Laravel application.
     */
    private function laravelApp(): Application
    {
        // Only create it once
        if ($this->laravelApp) {
            return $this->laravelApp;
        }

        $bootstrapFile = getcwd() . '/bootstrap/app.php';

        if (! file_exists($bootstrapFile)) {
            throw new RuntimeException(
                "Unable to locate `{$bootstrapFile}`: Bref tried to load that file to retrieve the Laravel app"
            );
        }

        $this->laravelApp = require $bootstrapFile;

        if (! $this->laravelApp instanceof Application) {
            throw new RuntimeException(sprintf(
                "Expected the `%s` file to return a %s object, instead it returned `%s`",
                $bootstrapFile,
                Application::class,
                is_object($this->laravelApp) ? get_class($this->laravelApp) : gettype($this->laravelApp),
            ));
        }

        return $this->laravelApp;
    }
}
