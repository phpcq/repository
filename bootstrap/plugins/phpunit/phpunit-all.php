<?php

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\ToolReportInterface;
use Phpcq\PluginApi\Version10\Util\JUnitReportAppender;

return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'phpunit';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to phpunit. For valid flags refer to the phpunit documentation.'
            )
            ->withDefaultValue([])
            ->isRequired();
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $buildConfig
    ): iterable {
        $args = [
            '--log-junit',
            $logFile = $buildConfig->getUniqueTempFile($this, 'junit-log.xml')
        ];

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $args[] = $value;
            }
        }

        $projectRoot = $buildConfig->getProjectConfiguration()->getProjectRootPath();
        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('phpunit', $args)
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer($this->createOutputTransformerFactory($logFile, $projectRoot))
            ->build();
    }

    private function createOutputTransformerFactory(
        string $logFile,
        string $rootDir
    ): OutputTransformerFactoryInterface {
        return new class ($logFile, $rootDir) implements OutputTransformerFactoryInterface {
            private $logFile;
            private $rootDir;

            public function __construct(string $logFile, string $rootDir)
            {
                $this->logFile = $logFile;
                $this->rootDir = $rootDir;
            }

            public function createFor(ToolReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->logFile, $this->rootDir, $report) implements OutputTransformerInterface {
                    /** @var string */
                    private $logFile;
                    /** @var string */
                    private $rootDir;
                    /** @var ToolReportInterface */
                    private $report;

                    public function __construct(string $logFile, string $rootDir, ToolReportInterface $report)
                    {
                        $this->logFile = $logFile;
                        $this->rootDir = $rootDir;
                        $this->report  = $report;
                    }

                    public function write(string $data, int $channel): void
                    {
                        // FIXME: do we also want to parse stdout/stderr?
                    }

                    public function finish(int $exitCode): void
                    {
                        $this->report->addAttachment('junit-log.xml')->fromFile($this->logFile)->end();
                        JUnitReportAppender::appendFileTo($this->report, $this->logFile, $this->rootDir);
                        $this->report->close(
                            $exitCode === 0 ? ToolReportInterface::STATUS_PASSED : ToolReportInterface::STATUS_FAILED
                        );
                    }
                };
            }
        };
    }
};
