<?php

/**
 * Tool home: https://github.com/sebastianbergmann/phpcpd
 */

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Exception\InvalidConfigurationException;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

/**
 * @psalm-type TSeverity = TaskReportInterface::SEVERITY_FATAL
 *  |TaskReportInterface::SEVERITY_MAJOR
 *  |TaskReportInterface::SEVERITY_MINOR
 *  |TaskReportInterface::SEVERITY_MARGINAL
 *  |TaskReportInterface::SEVERITY_INFO
 *  |TaskReportInterface::SEVERITY_NONE
 */
return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'phpcpd';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->supportDirectories();
        $configOptionsBuilder
            ->describeStringListOption('names', 'A list of file names to check.')
            ->isRequired()
            ->withDefaultValue(['*.php']);

        $configOptionsBuilder
            ->describeStringListOption('names_exclude', 'A list of file names to exclude.');

        $configOptionsBuilder->describeStringListOption(
            'regexps_exclude',
            'A list of paths regexps to exclude (example: "#var/.*_tmp#")'
        );

        $configOptionsBuilder
            ->describeIntOption('min_lines', 'Minimum number of identical lines.')
            ->isRequired()
            ->withDefaultValue(5);

        $configOptionsBuilder
            ->describeIntOption('min_tokens', 'Minimum number of identical tokens.')
            ->isRequired()
            ->withDefaultValue(70);

        $configOptionsBuilder
            ->describeBoolOption('fuzzy', 'Fuzz variable names')
            ->isRequired()
            ->withDefaultValue(false);

        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to phpcpd. For valid flags refer to the phpcpd documentation.'
            )
            ->withDefaultValue([])
            ->isRequired();

        $severityText = implode('", "', [
            TaskReportInterface::SEVERITY_NONE,
            TaskReportInterface::SEVERITY_INFO,
            TaskReportInterface::SEVERITY_MARGINAL,
            TaskReportInterface::SEVERITY_MINOR,
            TaskReportInterface::SEVERITY_MAJOR,
            TaskReportInterface::SEVERITY_FATAL,
        ]);

        $configOptionsBuilder
            ->describeStringOption(
                'severity',
                'Severity for detected duplications. Must be one of "' . $severityText . '"',
            )
            ->isRequired()
            ->withDefaultValue(TaskReportInterface::SEVERITY_MINOR);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $args = [
            '--log-pmd',
            $logFile = $environment->getUniqueTempFile($this, 'pmd-cpd.xml')
        ];

        if ($config->has('names')) {
            $args[] = '--names=' . implode(',', $config->getStringList('names'));
        }
        if ($config->has('names_exclude')) {
            $args[] = '--names-exclude=' . implode(',', $config->getStringList('names_exclude'));
        }
        if ($config->has('regexps_exclude')) {
            $args[] = '--regexps-exclude';
            $args[] = implode(',', $config->getStringList('regexps_exclude'));
        }
        $args[] = '--min-lines=' . (string) $config->getInt('min_lines');
        $args[] = '--min-tokens=' . (string) $config->getInt('min_tokens');

        if ($config->getBool('fuzzy')) {
            $args[] = '--fuzzy';
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                if (strpos($value, '--log-pmd') >= 0) {
                    throw new InvalidConfigurationException('Configuring a custom log file is not allowed.');
                }
                $args[] = $value;
            }
        }

        $rootDir  = $environment->getProjectConfiguration()->getProjectRootPath();
        /** @psalm-var TSeverity $severity */
        $severity = $config->getString('severity');

        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('phpcpd', array_merge($args, $config->getStringList('directories')))
            ->withOutputTransformer($this->createOutputTransformer($logFile, $rootDir, $severity))
            ->withWorkingDirectory($environment->getProjectConfiguration()->getProjectRootPath())
            ->build();
    }

    /** @psalm-param TSeverity $severity */
    private function createOutputTransformer(
        string $xmlFile,
        string $rootDir,
        string $severity
    ): OutputTransformerFactoryInterface {
        return new class ($xmlFile, $rootDir, $severity) implements OutputTransformerFactoryInterface {
            /** @var string */
            private $xmlFile;

            /** @var string */
            private $rootDir;

            /**
             * @var string
             * @psalm-var TSeverity
             */
            private $severity;

            /** @psalm-param TSeverity $severity */
            public function __construct(string $xmlFile, string $rootDir, string $severity)
            {
                $this->xmlFile  = $xmlFile;
                $this->rootDir  = $rootDir;
                $this->severity = $severity;
            }

            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class (
                    $this->xmlFile,
                    $report,
                    $this->rootDir,
                    $this->severity
                ) implements OutputTransformerInterface {
                    /** @var string */
                    private $xmlFile;
                    /** @var TaskReportInterface */
                    private $report;
                    /** @var string */
                    private $rootDir;
                    /**
                     * @var string
                     * @psalm-var TSeverity
                     */
                    private $severity;

                    /** @psalm-param TSeverity $severity */
                    public function __construct(
                        string $xmlFile,
                        TaskReportInterface $report,
                        string $rootDir,
                        string $severity
                    ) {
                        $this->xmlFile = $xmlFile;
                        $this->report  = $report;
                        if ('/' !== substr($rootDir, -1)) {
                            $rootDir .= '/';
                        }
                        $this->rootDir = $rootDir;
                        $this->severity = $severity;
                    }

                    public function write(string $data, int $channel): void
                    {
                    }

                    public function finish(int $exitCode): void
                    {
                        $xmlDocument = new DOMDocument('1.0');
                        $xmlDocument->load($this->xmlFile);
                        $rootNode = $xmlDocument->firstChild;

                        if (!$rootNode instanceof DOMNode) {
                            $this->report->close(
                                $exitCode === 0
                                    ? TaskReportInterface::STATUS_PASSED
                                    : TaskReportInterface::STATUS_FAILED
                            );
                            return;
                        }

                        foreach ($rootNode->childNodes as $childNode) {
                            if (!$childNode instanceof DOMElement) {
                                continue;
                            }

                            $message = 'Duplicate code fragment';
                            $taskReport = $this->report->addDiagnostic($this->severity, $message);
                            $numberOfLines = (int) $childNode->getAttribute('lines');

                            /** @var DOMElement $fileNode */
                            foreach ($childNode->getElementsByTagName('file') as $fileNode) {
                                $line = (int) $fileNode->getAttribute('line');
                                $taskReport
                                    ->forFile($this->getFileName($fileNode))
                                    ->forRange($line, null, ($line + $numberOfLines));
                            }
                        }

                        $this->report->addAttachment('phpcpd.xml')
                            ->fromFile($this->xmlFile)
                            ->setMimeType('application/xml')
                            ->end();

                        $this->report->close(
                            $exitCode === 0
                                ? TaskReportInterface::STATUS_PASSED
                                : TaskReportInterface::STATUS_FAILED
                        );
                    }

                    private function getFileName(DOMElement $element): string
                    {
                        $fileName = $element->getAttribute('path');
                        if (strpos($fileName, $this->rootDir) === 0) {
                            $fileName = substr($fileName, strlen($this->rootDir));
                        }

                        return $fileName;
                    }
                };
            }
        };
    }
};
