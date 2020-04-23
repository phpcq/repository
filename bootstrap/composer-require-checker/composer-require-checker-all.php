<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;

return new class implements ConfigurationPluginInterface {
    public function getName() : string
    {
        return 'composer-require-checker';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder) : void
    {
        $configOptionsBuilder
            ->describeStringOption('config_file', 'Path to configuration file')
            ->describeStringOption('composer_file', 'Path to the composer.json', 'composer.json');

        $configOptionsBuilder->describeStringOption(
            'custom_flags',
            'Any custom flags to pass to composer-require-checker. For valid flags refer to the composer-require-checker documentation.',
            );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig) : iterable
    {
        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('composer-require-checker', $this->buildArguments($config, $buildConfig))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->build();
    }

    private function buildArguments(array $config, BuildConfigInterface $buildConfig) : array
    {
        $arguments = ['check'];

        if (isset($config['config_file'])) {
            $arguments[] = '--config-file=' . $buildConfig->getProjectConfiguration()->getProjectRootPath() . '/' . $config['config_file'];
        }

        if (isset($config['composer_file'])) {
            $arguments[] = $buildConfig->getProjectConfiguration()->getProjectRootPath() . '/' . $config['composer_file'];
        }

        return $arguments;
    }
};