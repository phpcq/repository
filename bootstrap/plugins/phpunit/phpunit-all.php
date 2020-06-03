<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\Util\JUnitReportAppender;

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
        $args = [
            '--log-junit',
            $logFile = $buildConfig->getUniqueTempFile($this, 'junit-log.xml')
        ];
        if ('' !== ($values = $config['custom_flags'] ?? '')) {
            $args[] = $values;
        }

        $projectRoot = $buildConfig->getProjectConfiguration()->getProjectRootPath();
        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phpunit', $args)
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer(JUnitReportAppender::transformFile($logFile, $projectRoot))
            ->build();
    }
};
