<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\Util\JUnitReportAppender;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'phpspec';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->describeStringOption(
            'config_file',
            'The phpspec.yml configuration file',
            'phpspec.yml'
        );
        $configOptionsBuilder->describeStringOption(
            'custom_flags',
            'Any custom flags to pass to phpunit. For valid flags refer to the phpunit documentation.',
        );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        $args = ['run', '-c', $config['config_file'] ?? 'phpspec.yml', '--format=junit'];
        if ('' !== ($values = $config['custom_flags'] ?? '')) {
            $args[] = $values;
        }

        $projectRoot = $buildConfig->getProjectConfiguration()->getProjectRootPath();
        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phpspec', $args)
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer(JUnitReportAppender::transformBuffer($projectRoot))
            ->build();
    }
};
