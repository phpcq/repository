<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
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

        $configOptionsBuilder->describeStringOption(
            'custom_flags',
            'Any custom flags to pass to composer-require-checker. ' .
            'For valid flags refer to the composer-require-checker documentation.',
        );
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('composer-require-checker', $this->buildArguments($config, $buildConfig))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer($this->createOutputTransformer($config['composer_file'] ?? 'composer.json'))
            ->build();
    }

    private function buildArguments(array $config, BuildConfigInterface $buildConfig): array
    {
        $arguments = ['check'];

        $projectRoot = $buildConfig->getProjectConfiguration()->getProjectRootPath() . '/';
        if (isset($config['config_file'])) {
            $arguments[] = '--config-file=' . $projectRoot . $config['config_file'];
        }

        if (isset($config['composer_file'])) {
            $arguments[] = $projectRoot . $config['composer_file'];
        }

        return $arguments;
    }

    private function createOutputTransformer(string $composerFile): OutputTransformerInterface
    {
        return new class ($composerFile) implements OutputTransformerInterface {
            /** @var string */
            private $composerFile;
            /** @var BufferedLineReader */
            private $data;
            /** @var ToolReportInterface */
            private $report;

            public function __construct(string $composerFile)
            {
                $this->composerFile = $composerFile;
            }

            public function attach(ToolReportInterface $report): void
            {
                $this->report = $report;
                $this->data   = new BufferedLineReader();
            }

            public function write(string $data, int $channel): void
            {
                // Can not single channel, as require checker writes every output to STDOUT except for setup errors.
                $this->data->push($data);
            }

            public function detach(int $exitCode): void
            {
                $this->process();
                $this->report->finish(0 === $exitCode
                    ? ReportInterface::STATUS_PASSED
                    : ReportInterface::STATUS_FAILED);

                $this->report = null;
            }

            private function process(): void
            {
                // FIXME: this should be a state machine to parse incomplete output but for the moment it works.
                while (null !== $line = $this->data->fetch()) {
                    // If it is the version header, ignore it.
                    // eg. ComposerRequireChecker 2.1.0@0c66698d487fcb5c66cf07108e2180c818fb2e72
                    if (preg_match('#ComposerRequireChecker .*#', $line)) {
                        continue;
                    }

                    // If it is the usage suffix, ignore it.
                    // check [--config-file CONFIG-FILE] [--ignore-parse-errors] [--] [<composer-json>]
                    if (
                        preg_match(
                            '#check \[--config-file CONFIG-FILE] \[--ignore-parse-errors] \[--] \[<composer-json>]#',
                            $line
                        )
                    ) {
                        continue;
                    }

                    // Could be notification of missing dependencies.
                    // In LocateComposerPackageDirectDependenciesSourceFiles.php line 52:
                    if (preg_match('#In LocateComposerPackageDirectDependenciesSourceFiles.php#', $line)) {
                        $countEmpty = 0;
                        $error = '';
                        // Buffer up until two empty lines.
                        while (null !== $line = $this->data->fetch()) {
                            $line = trim($line);
                            if (empty($line)) {
                                $countEmpty++;
                                if (2 === $countEmpty) {
                                    break;
                                }
                                continue;
                            }
                            $countEmpty = 0;
                            $error .= $line;
                        }
                        $this->report->addDiagnostic('error', $error, $this->composerFile);
                    }


                    // Missing dependencies found, parse the table.
                    if (preg_match('#The following unknown symbols were found:#', $line)) {
                        /*
                        The following unknown symbols were found:
                        +----------------+--------------------+
                        | unknown symbol | guessed dependency |
                        +----------------+--------------------+
                        | DOMDocument    | ext-dom            |
                        | DOMElement     | ext-dom            |
                        | DOMNode        | ext-dom            |
                        +----------------+--------------------+
                        */

                        // Strip table head.
                        foreach (
                            [
                                '#^\+-*\+-*\+$#',
                                '#\|\s*unknown symbol\s*\|\s*guessed dependency\s*\|#',
                                '#^\+-*\+-*\+$#',
                            ] as $regex
                        ) {
                            if (1 !== preg_match($regex, $line = $this->data->fetch())) {
                                throw new \RuntimeException('Failed to parse line: ' . $line);
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
                                throw new \RuntimeException('Failed to parse line: ' . $line);
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
                            $this->report->addDiagnostic(
                                'error',
                                sprintf(
                                    'Missing dependency "%1$s" (used symbols: "%2$s")',
                                    $dependency,
                                    implode('", "', $symbols)
                                ),
                                $this->composerFile
                            );
                        }
                        if (!empty($unknown)) {
                            $this->report->addDiagnostic(
                                'error',
                                sprintf(
                                    'Unknown symbols found: "%1$s" - is there a dependency missing?',
                                    implode('", "', $unknown)
                                ),
                                $this->composerFile
                            );
                        }
                    }

                    if (preg_match('#There were no unknown symbols found.#', $line)) {
                        $this->report->addDiagnostic('info', $line, $this->composerFile);
                    }
                }
            }
        };
    }
};
