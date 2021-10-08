<?php

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Exception\ReportFileNotFoundException;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;
use Phpcq\PluginApi\Version10\Util\BufferedLineReader;
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
        EnvironmentInterface $environment
    ): iterable {
        $args = [
            '--log-junit',
            $logFile = $environment->getUniqueTempFile($this, 'junit-log.xml')
        ];

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $args[] = $value;
            }
        }

        $projectRoot = $environment->getProjectConfiguration()->getProjectRootPath();
        yield $environment
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

            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->logFile, $this->rootDir, $report) implements OutputTransformerInterface {
                    /** @var string */
                    private $logFile;
                    /** @var string */
                    private $rootDir;
                    /** @var TaskReportInterface */
                    private $report;
                    /** @var BufferedLineReader */
                    private $stdOut;
                    /** @var BufferedLineReader */
                    private $stdErr;

                    public function __construct(string $logFile, string $rootDir, TaskReportInterface $report)
                    {
                        $this->logFile = $logFile;
                        $this->rootDir = $rootDir;
                        $this->report  = $report;
                        $this->stdOut  = BufferedLineReader::create();
                        $this->stdErr  = BufferedLineReader::create();
                    }

                    public function write(string $data, int $channel): void
                    {
                        if (OutputInterface::CHANNEL_STDERR === $channel) {
                            $this->stdErr->push($data);
                            return;
                        }
                        $this->stdOut->push($data);
                    }

                    public function finish(int $exitCode): void
                    {
                        try {
                            JUnitReportAppender::appendFileTo($this->report, $this->logFile, $this->rootDir);
                            $this->report->addAttachment('junit-log.xml')->fromFile($this->logFile)->end();
                        } catch (ReportFileNotFoundException $exception) {
                            $this->report->addDiagnostic(
                                TaskReportInterface::SEVERITY_FATAL,
                                'Report file was not produced: ' . $this->logFile
                            );
                            $contents = [];
                            while (null !== $line = $this->stdOut->fetch()) {
                                $contents[] = $line;
                            }
                            if (!empty($contents)) {
                                $this->report
                                    ->addAttachment('output.log')
                                    ->fromString(implode("\n", $contents))
                                    ->setMimeType('text/plain')
                                    ->end();
                            }
                            $contents = [];
                            while (null !== $line = $this->stdErr->fetch()) {
                                $contents[] = $line;
                            }
                            if (!empty($contents)) {
                                $this->report
                                    ->addAttachment('error.log')
                                    ->fromString(implode("\n", $contents))
                                    ->setMimeType('text/plain')
                                    ->end();
                            }
                            $exitCode = 1;
                        }
                        $this->report->close(
                            $exitCode === 0 ? TaskReportInterface::STATUS_PASSED : TaskReportInterface::STATUS_FAILED
                        );
                    }
                };
            }
        };
    }
};
