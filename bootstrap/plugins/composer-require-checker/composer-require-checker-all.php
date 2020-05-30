<?php

use Phpcq\PluginApi\Version10\BuildConfigInterface;
use Phpcq\PluginApi\Version10\ConfigurationOptionsBuilderInterface;
use Phpcq\PluginApi\Version10\ConfigurationPluginInterface;
use Phpcq\PluginApi\Version10\OutputInterface;
use Phpcq\PluginApi\Version10\PostProcessorInterface;
use Phpcq\PluginApi\Version10\ReportInterface;
use Phpcq\PluginApi\Version10\ToolReportInterface;

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
            ->withPostProcessor($this->createPostProcessor($config['composer_file'] ?? 'composer.json'))
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

    private function createPostProcessor(string $composerFile): PostProcessorInterface
    {
        return new class($composerFile) implements PostProcessorInterface {
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
                    $status   = ReportInterface::STATUS_PASSED;
                } else {
                    $severity = 'error';
                    $status   = ReportInterface::STATUS_FAILED;
                }

                $report->addError($severity, trim($consoleOutput), $this->composerFile);
                $report->finish($status);
            }
        };
    }
};
