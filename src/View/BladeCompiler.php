<?php

namespace Bref\LaravelBridge\View;

use Illuminate\View\Compilers\BladeCompiler as LaravelBladeCompiler;

/**
 * Reads the views compiled at build time (`php artisan view:cache`) from the read-only package,
 * and compiles the other views (`Blade::render()`, inline components...) in the compiled path.
 *
 * @internal
 */
class BladeCompiler extends LaravelBladeCompiler
{
    /**
     * The compiled views of the package, by view path (null when the package has none).
     *
     * @var array<string, string|null>
     */
    private array $packagedViews = [];

    /**
     * @param string $packagedPath The directory of the views compiled at build time.
     * @param mixed ...$arguments The arguments of Laravel's BladeCompiler.
     */
    public function __construct(private string $packagedPath, mixed ...$arguments)
    {
        parent::__construct(...$arguments);
    }

    public function getCompiledPath($path)
    {
        return $this->packagedView($path) ?? parent::getCompiledPath($path);
    }

    public function isExpired($path)
    {
        return $this->packagedView($path) === null && parent::isExpired($path);
    }

    public function compile($path = null)
    {
        // The views compiled at build time are up to date, and the package is read-only
        if ($path && $this->packagedView($path) !== null) {
            return;
        }

        parent::compile($path);
    }

    private function packagedView(string $path): ?string
    {
        if (! array_key_exists($path, $this->packagedViews)) {
            $packagedView = $this->packagedPath . '/' . basename(parent::getCompiledPath($path));

            // Zip packages date every file 1980-01-01: a view as old as its compiled file is up to date
            $this->packagedViews[$path] = $this->shouldCache
                && is_file($packagedView)
                && $this->files->lastModified($path) <= $this->files->lastModified($packagedView)
                ? $packagedView
                : null;
        }

        return $this->packagedViews[$path];
    }
}
