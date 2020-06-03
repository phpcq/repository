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
        return 'composer-normalize';
    }

    public function describeOptions(ConfigurationOptionsBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeBoolOption('dry_run', 'Show the results of normalizing, but do not modify any files', true)
            ->describeStringOption('file', 'Path to composer.json file relative to project root')
            ->describeIntOption(
                'indent_size',
                'Indent size (an integer greater than 0); should be used with the indent_style option',
                2
            )
            ->describeStringOption(
                'indent_style',
                'Indent style (one of "space", "tab"); should be used with the indent_size option',
                'space'
            )
            ->describeStringOption('no_update_lock', 'Path to the composer.json', 'composer.json');
    }

    public function processConfig(array $config, BuildConfigInterface $buildConfig): iterable
    {
        $composerJson = $config['file'] ?? 'composer.json';
        assert(is_string($composerJson));

        yield $buildConfig
            ->getTaskFactory()
            ->buildRunPhar('composer-normalize', $this->buildArguments($config))
            ->withWorkingDirectory($buildConfig->getProjectConfiguration()->getProjectRootPath())
            ->withOutputTransformer($this->createOutputTransformerFactory($composerJson))
            ->build();
    }

    /** @return string[] */
    private function buildArguments(array $config): array
    {
        $arguments = [];

        if (isset($config['file'])) {
            $arguments[] = $config['file'];
        }

        if (!isset($config['dry_run']) || $config['dry_run']) {
            $arguments[] = '--dry-run';
        }

        if (isset($config['indent_size'])) {
            $arguments[] = '--indent-size';
            $arguments[] = $config['indent_size'];
        }

        if (isset($config['indent_style'])) {
            $arguments[] = '--indent-style';
            $arguments[] = $config['indent_style'];
        }

        if (isset($config['no_update_lock'])) {
            $arguments[] = '--no-update-lock';
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
                        $this->data         = new BufferedLineReader();
                    }

                    public function write(string $data, int $channel): void
                    {
                        // Can not single channel, as require checker writes every output to STDOUT except for setup errors.
                        $this->data->push($data);
                    }

                    public function finish(int $exitCode): void
                    {
                        $this->process(
                            0 === $exitCode ? ToolReportInterface::SEVERITY_INFO : ToolReportInterface::SEVERITY_ERROR
                        );
                        $this->report->finish(0 === $exitCode
                            ? ReportInterface::STATUS_PASSED
                            : ReportInterface::STATUS_FAILED);

                        $this->report = null;
                    }

                    private function process(string $severity): void
                    {
                        // FIXME: should parse the data instead of appending.
                        $diagnostic = '';
                        while (null !== $line = $this->data->fetch()) {
                            $diagnostic .= $line;
                        }
                        $this->report->addDiagnostic($severity, $diagnostic, $this->composerFile);
                    }
                };
            }
        };
    }
};
