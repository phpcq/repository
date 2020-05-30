<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\PostProcessorInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;
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
            ->withPostProcessor(new class($logFile, $projectRoot) implements PostProcessorInterface {
                private $logFile;
                private $rootDir;

                public function __construct(string $logFile, string $rootDir)
                {
                    $this->logFile = $logFile;
                    $this->rootDir = $rootDir;
                }

                public function process(
                    ToolReportInterface $report,
                    string $consoleOutput,
                    int $exitCode,
                    OutputInterface $output
                ): void {
                    $report->addAttachment($this->logFile, 'junit-log.xml');
                    JUnitReportAppender::appendTo($report, $this->logFile, $this->rootDir);
                    $report->finish($exitCode === 0 ? ToolReportInterface::STATUS_PASSED : ToolReportInterface::STATUS_FAILED);
                }
            })
            ->build();
    }
};
