<?php

/**
 * Tool home: https://github.com/sebastianbergmann/phpcpd
 */

declare(strict_types=1);

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\InvalidConfigException;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\ReportInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'phpcpd';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->describeArrayOption(
            'names',
            'A list of file names to check.',
            ['*.php']
        );

        $configOptionsBuilder->describeArrayOption(
            'names_exclude',
            'A list of file names to exclude.'
        );

        $configOptionsBuilder->describeArrayOption(
            'regexps_exclude',
            'A list of paths regexps to exclude (example: "#var/.*_tmp#")'
        );

        $configOptionsBuilder->describeIntOption(
            'min_lines',
            'Minimum number of identical lines.',
            5
        );

        $configOptionsBuilder->describeIntOption(
            'min_tokens',
            'Minimum number of identical tokens.',
            70
        );

        $configOptionsBuilder->describeBoolOption(
            'fuzzy',
            'Fuzz variable names',
            false
        );

        $configOptionsBuilder->describeArrayOption(
            'custom_flags',
            'Any custom flags to pass to phpcpd. For valid flags refer to the phpcpd documentation.'
        );

        $configOptionsBuilder->describeArrayOption(
            'directories',
            'Source directories to be analyzed with phpcpd.'
        );

        $configOptionsBuilder->describeStringOption(
            'severity',
            'Severity for detected duplications. Must be one of "' . ToolReportInterface::SEVERITY_INFO . '", "'
            . ToolReportInterface::SEVERITY_NOTICE . '", "' . ToolReportInterface::SEVERITY_WARNING . '" or "'
            . ToolReportInterface::SEVERITY_ERROR . '"',
            ToolReportInterface::SEVERITY_WARNING
        );
    }

    public function processConfig(array $toolConfig, BuildConfigInterface $buildConfig): iterable
    {
        foreach ($this->processDirectories($toolConfig) as $config) {
            $args = [
                '--log-pmd',
                $logFile = $buildConfig->getUniqueTempFile($this, 'pmd-cpd.xml')
            ];

            if ('' !== ($values = $this->commaValues($config, 'names'))) {
                $args[] = '--names=' . $values;
            }
            if ('' !== ($values = $this->commaValues($config, 'names_exclude'))) {
                $args[] = '--names-exclude=' . $values;
            }
            if ('' !== ($values = $this->commaValues($config, 'regexps_exclude'))) {
                $args[] = '--regexps-exclude';
                $args[] = $values;
            }
            if ('' !== ($values = $config['min_lines'] ?? '')) {
                $args[] = '--min-lines=' . $values;
            }
            if ('' !== ($values = $config['min_tokens'] ?? '')) {
                $args[] = '--min-tokens=' . $values;
            }
            if ($config['fuzzy'] ?? false) {
                $args[] = '--fuzzy';
            }

            if ([] !== ($values = $config['custom_flags'] ?? [])) {
                foreach ($values as $value) {
                    if (strpos($value, '--log-pmd') >= 0) {
                        throw new InvalidConfigException('Configuring a custom log file is not allowed.');
                    }
                    $args[] = (string) $value;
                }
            }

            $rootDir  = $buildConfig->getProjectConfiguration()->getProjectRootPath();
            $severity = $config['severity'] ?? ToolReportInterface::SEVERITY_WARNING;

            yield $buildConfig
                ->getTaskFactory()
                ->buildRunPhar('phpcpd', array_merge($args, array_values($config['directories'])))
                ->withOutputTransformer($this->createOutputTransformer($logFile, $rootDir, $severity))
                ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
                ->build();
        }
    }

    /**
     * Process the directory list.
     *
     * @param array $toolConfig The tool configuration
     *
     * @return array
     */
    private function processDirectories(array $toolConfig): array
    {
        $configs = [
            array_merge($toolConfig, ['directories' => []])
        ];

        foreach ($toolConfig['directories'] as $directory => $dirConfig) {
            if (null === $dirConfig) {
                $configs[0]['directories'][] = $directory;
                continue;
            }

            $configs[] = array_merge(
                $dirConfig,
                ['directories' => [$directory]]
            );
        }

        return $configs;
    }

    private function commaValues(array $config, string $key): string
    {
        if (!isset($config[$key])) {
            return '';
        }
        return implode(',', (array) $config[$key]);
    }

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

            /** @var string */
            private $severity;

            public function __construct(string $xmlFile, string $rootDir, string $severity)
            {
                $this->xmlFile  = $xmlFile;
                $this->rootDir  = $rootDir;
                $this->severity = $severity;
            }

            public function createFor(ToolReportInterface $report): OutputTransformerInterface
            {
                return new class (
                    $this->xmlFile,
                    $report,
                    $this->rootDir,
                    $this->severity
                ) implements OutputTransformerInterface {
                    /** @var string */
                    private $xmlFile;
                    /** @var ToolReportInterface */
                    private $report;
                    /** @var string */
                    private $rootDir;
                    /** @var string */
                    private $severity;

                    public function __construct(
                        string $xmlFile,
                        ToolReportInterface $report,
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
                                $exitCode === 0 ? ReportInterface::STATUS_PASSED : ReportInterface::STATUS_FAILED
                            );
                            return;
                        }

                        foreach ($rootNode->childNodes as $childNode) {
                            if (!$childNode instanceof DOMElement) {
                                continue;
                            }

                            $message = 'Duplicate code fragment';
                            $toolReport = $this->report->addDiagnostic($this->severity, $message);
                            $numberOfLines = (int) $childNode->getAttribute('lines');

                            /** @var DOMElement $fileNode */
                            foreach ($childNode->getElementsByTagName('file') as $fileNode) {
                                $line = (int) $fileNode->getAttribute('line');
                                $toolReport
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
                                ? ReportInterface::STATUS_PASSED
                                : ReportInterface::STATUS_FAILED
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
