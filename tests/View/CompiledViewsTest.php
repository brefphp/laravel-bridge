<?php

declare(strict_types=1);

namespace Bref\LaravelBridge\Tests\View;

use Bref\LaravelBridge\BrefServiceProvider;
use Bref\LaravelBridge\StorageDirectories;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Compilers\BladeCompiler as LaravelBladeCompiler;
use Orchestra\Testbench\TestCase;

class CompiledViewsTest extends TestCase
{
    /**
     * The application directory: like /var/task, with `tmp/views` like /tmp/storage/framework/views.
     */
    private string $root;

    protected function setUp(): void
    {
        $_SERVER['LAMBDA_TASK_ROOT'] = __DIR__;

        parent::setUp();

        // Real path: the view finder resolves symlinks, `view:cache` too
        $this->root = realpath(sys_get_temp_dir()) . '/bref-compiled-views-' . bin2hex(random_bytes(4));
        mkdir("$this->root/resources/views", 0777, true);
        mkdir("$this->root/storage/framework/views", 0777, true);
        mkdir("$this->root/tmp/views", 0777, true);
        file_put_contents("$this->root/resources/views/precompiled.blade.php", 'Hello {{ $name }}');
        file_put_contents("$this->root/resources/views/other.blade.php", 'Hi {{ $name }}');

        // Compiled at build time by `php artisan view:cache`, with a different content to tell them apart
        $compiler = new LaravelBladeCompiler(new Filesystem, "$this->root/storage/framework/views");
        $compiledPath = $compiler->getCompiledPath("$this->root/resources/views/precompiled.blade.php");
        file_put_contents($compiledPath, 'Hello <?php echo e($name); ?> from the package');

        // Zip packages date every file 1980-01-01
        foreach ([...glob("$this->root/resources/views/*"), $compiledPath] as $file) {
            touch($file, 315532800);
        }

        $this->app->useStoragePath("$this->root/storage");
        $this->app['view']->addLocation("$this->root/resources/views");
        config()->set('view.compiled', "$this->root/tmp/views");
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LAMBDA_TASK_ROOT']);
        chmod("$this->root/storage/framework/views", 0777);
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function testItReadsTheViewsCompiledAtBuildTimeFromThePackage(): void
    {
        $this->makePackageReadOnly();

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame("$this->root/tmp/views", config('view.compiled'));
        $this->assertSame('Hello Bref from the package', view('precompiled', ['name' => 'Bref'])->render());
        $this->assertSame([], glob("$this->root/tmp/views/*"));
    }

    public function testItCompilesTheOtherViewsInTheCompiledPath(): void
    {
        $this->makePackageReadOnly();

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame('Hi Bref', view('other', ['name' => 'Bref'])->render());
        $this->assertSame('Hey Bref', Blade::render('Hey {{ $name }}', ['name' => 'Bref']));
        // other.blade.php, plus the template of Blade::render() and its compiled view
        $this->assertCount(3, glob("$this->root/tmp/views/*"));
        $this->assertCount(1, glob("$this->root/storage/framework/views/*"));
    }

    public function testItCompilesAgainTheViewsThatChangedAfterTheBuild(): void
    {
        touch("$this->root/resources/views/precompiled.blade.php");
        $this->makePackageReadOnly();

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame('Hello Bref', view('precompiled', ['name' => 'Bref'])->render());
        $this->assertCount(1, glob("$this->root/tmp/views/*"));
    }

    public function testItCompilesViewsInTmpWhenTheCompiledPathIsReadOnly(): void
    {
        // E.g. the configuration was cached at /var/task outside of Bref's Docker image
        config()->set('view.compiled', "$this->root/storage/framework/views");
        $this->makePackageReadOnly();

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame(StorageDirectories::Path . '/framework/views', config('view.compiled'));
        $this->assertSame('Hello Bref from the package', view('precompiled', ['name' => 'Bref'])->render());
    }

    public function testItCompilesViewsInThePackageWhenBuildingTheApplication(): void
    {
        // The cached configuration points to /tmp/storage/framework/views, which does not exist in the build container
        config()->set('view.compiled', "$this->root/tmp/missing");

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame("$this->root/storage/framework/views", config('view.compiled'));
        $this->assertSame(LaravelBladeCompiler::class, get_class(app('blade.compiler')));
    }

    public function testItCompilesViewsInTmpWhenTheApplicationHasNoCompiledViews(): void
    {
        $this->app->useStoragePath("$this->root/missing");
        config()->set('view.compiled', "$this->root/tmp/missing");

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame(StorageDirectories::Path . '/framework/views', config('view.compiled'));
        $this->assertSame(LaravelBladeCompiler::class, get_class(app('blade.compiler')));
    }

    public function testItKeepsTheCompilerWhenItIsAlreadyResolved(): void
    {
        $this->makePackageReadOnly();
        Blade::directive('greet', fn () => 'Greetings');

        (new BrefServiceProvider($this->app))->register();

        $this->assertSame('Greetings', Blade::render('@greet'));
    }

    private function makePackageReadOnly(): void
    {
        chmod("$this->root/storage/framework/views", 0555);
        if (is_writable("$this->root/storage/framework/views")) {
            $this->markTestSkipped('Directories cannot be made read-only when running as root');
        }
    }
}
