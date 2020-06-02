<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\PostProcessorInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;

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
            ->withPostProcessor($this->createPostProcessor($composerJson))
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

    private function createPostProcessor(string $composerFile): PostProcessorInterface
    {
        return new class ($composerFile) implements PostProcessorInterface {
            private $composerFile;

            public function __construct(string $composerFile)
            {
                $this->composerFile = $composerFile;
            }

            public function process(
                ToolReportInterface $report,
                string $consoleOutput,
                int $exitCode,
                OutputInterface $output
            ): void {
                if ($exitCode === 0) {
                    $severity = 'info';
                    $status   = ToolReportInterface::STATUS_PASSED;
                } else {
                    $severity = 'error';
                    $status   = ToolReportInterface::STATUS_FAILED;
                }

                $report->addDiagnostic($severity, trim($consoleOutput), $this->composerFile);
                $report->finish($status);
            }
        };
    }
};
