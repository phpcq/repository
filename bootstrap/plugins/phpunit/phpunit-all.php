<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'phpunit';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->describeStringOption(
            'custom_flags',
            'Any custom flags to pass to phpunit. For valid flags refer to the phpunit documentation.',
        );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        $args = [];
        if ('' !== ($values = $config['custom_flags'] ?? '')) {
            $args[] = $values;
        }

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phpunit', $args)
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->build();
    }
};
