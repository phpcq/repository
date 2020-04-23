<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;

return new class implements ConfigurationPluginInterface {
    public function getName() : string
    {
        return 'composer-normalize';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder) : void
    {
        $configOptionsBuilder
            ->describeBoolOption('dry_run', 'Show the results of normalizing, but do not modify any files', true)
            ->describeStringOption('file', 'Path to composer.json file relative to project root')
            ->describeIntOption('indent_size', 'Indent size (an integer greater than 0); should be used with the indent_style option', 2)
            ->describeStringOption('indent_style', 'Indent style (one of "space", "tab"); should be used with the indent_size option', 'space')
            ->describeStringOption('no_update_lock', 'Path to the composer.json', 'composer.json');
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig) : iterable
    {
        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('composer-normalize', $this->buildArguments($config, $buildConfig))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->build();
    }

    private function buildArguments(array $config, BuildConfigInterface $buildConfig) : array
    {
        $arguments = [];

        if (isset($config['file'])) {
            $arguments[] = $config['file'];
        }

        if (!isset($config['dry_run']) || $config['dry_run']) {
            $arguments[] = '--dry-run';
        }

        if (isset($config['indent_size'])) {
            $arguments[] = '--indent-size';
            $arguments[] = $config['indent_size'];
        }

        if (isset($config['indent_style'])) {
            $arguments[] = '--indent-style';
            $arguments[] = $config['indent_style'];
        }

        if (isset($config['no_update_lock'])) {
            $arguments[] = '--no-update-lock';
        }

        return $arguments;
    }
};