<?php

namespace Bref\LaravelBridge\Console;

use Psy\CodeCleaner\NoReturnValue;
use Psy\Shell;

class LambdaShell extends Shell
{
    protected bool $contextRestored = false;

    public function setScopeVariables(array $vars)
    {
        parent::setScopeVariables($vars);

        // Only write new context data when the context was restored
        if ($this->contextRestored) {
            $excludedSpecialVars = array_diff($this->getScopeVariables(false), $this->getSpecialScopeVariables(false));
            $this->writeContextData($excludedSpecialVars);
        }
    }

    public function writeContextData(array $vars): void
    {
        $context = base64_encode(serialize($vars));

        $this->writeStdout("[CONTEXT]{$context}[END_CONTEXT]");
    }

    public function writeReturnValueData($ret): void
    {
        if ($ret instanceof NoReturnValue) {
            return;
        }

        $prompt = '= ';
        $indent = \str_repeat(' ', \strlen($prompt));
        $formatted = $this->presentValue($ret);
        $formattedRetValue = \sprintf('<whisper>%s</whisper>', $prompt);
        $formatted = $formattedRetValue . str_replace(\PHP_EOL, \PHP_EOL . $indent, $formatted);
        $this->writeStdout("[RETURN]{$formatted}[END_RETURN]");
    }

    public function restoreContextData(string $context): void
    {
        if ($returnVars = unserialize(base64_decode($context))) {
            $this->setScopeVariables($returnVars);
        }
    }

    public function setContextRestored($contextRestored): self
    {
        $this->contextRestored = $contextRestored;

        return $this;
    }
}
