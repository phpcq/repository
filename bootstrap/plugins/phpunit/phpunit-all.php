<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
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
                        $this->report->addAttachment($this->logFile, 'junit-log.xml');
                        JUnitReportAppender::appendFileTo($this->report, $this->logFile, $this->rootDir);
                        $this->report->finish(
                            $exitCode === 0 ? ToolReportInterface::STATUS_PASSED : ToolReportInterface::STATUS_FAILED
                        );
                    }
                };
            }
        };
    }
};
