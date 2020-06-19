<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'box';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        // TODO: add options here - this should be all options supported by the json
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        // TODO: dump options to temporary config json here.
        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('box', ['compile'])
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->build();
    }
};
