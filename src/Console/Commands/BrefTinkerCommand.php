<?php

namespace Bref\LaravelBridge\Console\Commands;

use Bref\LaravelBridge\Console\LambdaShell;
use Illuminate\Support\Env;
use Laravel\Tinker\ClassAliasAutoloader;
use Laravel\Tinker\Console\TinkerCommand;
use Psy\Configuration;
use Psy\VersionUpdater\Checker;
use Symfony\Component\Console\Input\InputOption;

class BrefTinkerCommand extends TinkerCommand
{
    protected $name = 'bref:tinker';

    protected $description = 'This is an internal used by the bref tinker command. Do not call it directly.';

    protected $hidden = true;

    public function handle()
    {
        $this->getApplication()->setCatchExceptions(false);

        $config = Configuration::fromInput($this->input);
        $config->setUpdateCheck(Checker::NEVER);

        $config->getPresenter()->addCasters(
            $this->getCasters()
        );

        if ($this->option('execute')) {
            $config->setRawOutput(true);
        }

        $shell = new LambdaShell($config);
        $shell->addCommands($this->getCommands());
        $shell->setIncludes($this->argument('include'));

        $path = Env::get('COMPOSER_VENDOR_DIR', $this->getLaravel()->basePath() . DIRECTORY_SEPARATOR . 'vendor');

        $path .= '/composer/autoload_classmap.php';

        $config = $this->getLaravel()->make('config');

        $loader = ClassAliasAutoloader::register(
            $shell,
            $path,
            $config->get('tinker.alias', []),
            $config->get('tinker.dont_alias', []),
        );

        if ($code = $this->option('execute')) {
            if ($context = $this->option('context')) {
                $shell->restoreContextData($context);
            }

            $shell->setContextRestored(true);

            try {
                $shell->setOutput($this->output);
                $shell->writeReturnValueData($shell->execute(base64_decode($code)));
            } finally {
                $loader->unregister();
            }

            return 0;
        }

        try {
            return $shell->run();
        } finally {
            $loader->unregister();
        }
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['execute', null, InputOption::VALUE_OPTIONAL, 'Execute the given code using Tinker'],
            ['context', null, InputOption::VALUE_OPTIONAL, 'The context data contains the defined vars'],
        ];
    }
}
