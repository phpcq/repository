<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\ReportInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;
use Phpcq\PluginApi\Version10\Util\BufferedLineReader;

return new class implements ConfigurationPluginInterface {
    public function getName(): string
    {
        return 'composer-require-checker';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeStringOption('config_file', 'Path to configuration file')
            ->describeStringOption('composer_file', 'Path to the composer.json', 'composer.json');

        $configOptionsBuilder->describeArrayOption(
            'custom_flags',
            'Any custom flags to pass to composer-require-checker. ' .
            'For valid flags refer to the composer-require-checker documentation.',
        );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        $composerJson = $config['composer_file'] ?? 'composer.json';
        assert(is_string($composerJson));

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('composer-require-checker', $this->buildArguments($config, $buildConfig))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer($this->createOutputTransformerFactory($composerJson))
            ->build();
    }

    /** @psalm-return array<int, string> */
    private function buildArguments(array $config, BuildConfigInterface $buildConfig): array
    {
        $arguments = ['check'];

        $projectRoot = $buildConfig->getProjectConfiguration()->getProjectRootPath() . '/';
        if (isset($config['config_file'])) {
            $arguments[] = '--config-file=' . $projectRoot . (string) $config['config_file'];
        }

        if (isset($config['composer_file'])) {
            $arguments[] = $projectRoot . (string) $config['composer_file'];
        }

        if ([] !== ($values = $config['custom_flags'] ?? [])) {
            foreach ($values as $value) {
                $arguments[] = (string) $value;
            }
        }

        return $arguments;
    }

    private function createOutputTransformerFactory(string $composerFile): OutputTransformerFactoryInterface
    {
        return new class ($composerFile) implements OutputTransformerFactoryInterface {
            /** @var string */
            private $composerFile;

            public function __construct(string $composerFile)
            {
                $this->composerFile = $composerFile;
            }

            public function createFor(ToolReportInterface $report): OutputTransformerInterface
            {
                return new class ($this->composerFile, $report) implements OutputTransformerInterface {

                    // eg. ComposerRequireChecker 2.1.0@0c66698d487fcb5c66cf07108e2180c818fb2e72
                    private const REGEX_HEADER = '#ComposerRequireChecker [0-9]*\.[0-9]*\.[0-9]*#';

                    private const REGEX_USAGE_SUMMARY =
                        '#check \[--config-file CONFIG-FILE] \[--ignore-parse-errors] \[--] \[<composer-json>]#';

                    /** @var string */
                    private $composerFile;
                    /** @var BufferedLineReader */
                    private $data;
                    /** @var ToolReportInterface */
                    private $report;

                    public function __construct(string $composerFile, ToolReportInterface $report)
                    {
                        $this->composerFile = $composerFile;
                        $this->report       = $report;
                        $this->data         = BufferedLineReader::create();
                    }

                    public function write(string $data, int $channel): void
                    {
                        $this->data->push($data);
                    }

                    public function finish(int $exitCode): void
                    {
                        $this->process();
                        $this->report->finish(0 === $exitCode
                            ? ReportInterface::STATUS_PASSED
                            : ReportInterface::STATUS_FAILED);
                    }

                    private function logDiagnostic(string $message, string $severity): void
                    {
                        $this->report->addDiagnostic($severity, $message)->forFile($this->composerFile)->end()->end();
                    }

                    private function process(): void
                    {
                        // FIXME: this should be a state machine to parse incomplete output but for the moment it works.
                        $unknown = [];
                        while (null !== $line = $this->data->peek()) {
                            if ($this->isLineIgnored($line)) {
                                $this->data->fetch();
                                continue;
                            }

                            // Could be notification of missing dependencies.
                            // In LocateComposerPackageDirectDependenciesSourceFiles.php line 52:
                            if (preg_match('#In LocateComposerPackageDirectDependenciesSourceFiles\.php#', $line)) {
                                $this->data->fetch();
                                $this->processLocateComposerPackageDirectDependenciesSourceFiles();
                                continue;
                            }

                            // Missing dependencies found, parse the table.
                            if (preg_match('#The following unknown symbols were found:#', $line)) {
                                $this->data->fetch();
                                $this->processMissingSymbols();
                                continue;
                            }

                            if (preg_match('#There were no unknown symbols found\.#', $line)) {
                                $this->data->fetch();
                                $this->logDiagnostic($line, 'info');
                                continue;
                            }
                            $unknown[] = $line;
                            $this->data->fetch();
                        }
                        if ([] !== $unknown) {
                            $this->logDiagnostic(
                                'Did not understand the following output from composer-require-checker: ' .
                                implode("\n", $unknown),
                                'warning'
                            );
                            $this->report
                                ->addAttachment('composer-require-checker.log')
                                    ->fromString($this->data->getData())
                                ->end();
                        }
                    }

                    private function isLineIgnored(string $line): bool
                    {
                        if ('' === $line) {
                            return true;
                        }
                        // If it is the version header, ignore it.
                        if (preg_match(self::REGEX_HEADER, $line)) {
                            return true;
                        }

                        // If it is the usage suffix, ignore it.
                        if (preg_match(self::REGEX_USAGE_SUMMARY, $line)) {
                            $this->data->fetch();
                            return true;
                        }

                        return false;
                    }

                    private function processLocateComposerPackageDirectDependenciesSourceFiles(): void
                    {
                        // Format is:
                        // \n\n<message>\n
                        $error = '';
                        // Buffer up until two empty lines.
                        while (null !== $line = $this->data->fetch()) {
                            if (!$this->isLineIgnored($line)) {
                                $error .= $line;
                            }
                        }
                        if ('' !== $error) {
                            $this->logDiagnostic($error, 'error');
                        }
                    }

                    private function processMissingSymbols(): void
                    {
                        /*
                         * The following unknown symbols were found:
                         * +----------------+--------------------+
                         * | unknown symbol | guessed dependency |
                         * +----------------+--------------------+
                         * | DOMDocument    | ext-dom            |
                         * | DOMElement     | ext-dom            |
                         * | DOMNode        | ext-dom            |
                         * +----------------+--------------------+
                        */

                        // Strip table head.
                        foreach (
                            [
                                '#^\+-*\+-*\+$#',
                                '#\|\s*unknown symbol\s*\|\s*guessed dependency\s*\|#',
                                '#^\+-*\+-*\+$#',
                            ] as $regex
                        ) {
                            if (1 !== preg_match($regex, $line = (string) $this->data->fetch())) {
                                throw new RuntimeException('Failed to parse line: ' . $line);
                            }
                        }
                        // List missing dependencies.
                        $dependencies = [];
                        $unknown      = [];
                        while (null !== $line = $this->data->fetch()) {
                            if (preg_match('#^\+-*\+-*\+$#', $line)) {
                                // End of table.
                                break;
                            }
                            if (!preg_match('#\|\s*(?<symbol>.*)\s*\|\s*(?<dependency>.*)?\s*\|#', $line, $matches)) {
                                throw new RuntimeException('Failed to parse line: ' . $line);
                            }
                            $dependency = trim($matches['dependency'] ?? '');
                            $symbol     = trim($matches['symbol'] ?? '');
                            if ('' === $dependency) {
                                $unknown[] = $symbol;
                                continue;
                            }
                            if (!isset($dependencies[$dependency])) {
                                $dependencies[$dependency] = [];
                            }
                            $dependencies[$dependency][] = $symbol;
                        }
                        foreach ($dependencies as $dependency => $symbols) {
                            $this->logDiagnostic(
                                sprintf(
                                    'Missing dependency "%1$s" (used symbols: "%2$s")',
                                    $dependency,
                                    implode('", "', $symbols)
                                ),
                                'error'
                            );
                        }
                        if (!empty($unknown)) {
                            $this->logDiagnostic(
                                sprintf(
                                    'Unknown symbols found: "%1$s" - is there a dependency missing?',
                                    implode('", "', $unknown)
                                ),
                                'error'
                            );
                        }
                    }
                };
            }
        };
    }
};
