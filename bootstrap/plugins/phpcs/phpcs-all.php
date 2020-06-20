<?php

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Util\CheckstyleReportAppender;

return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'phpcs';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder->supportDirectories();
        $configOptionsBuilder
            ->describeStringOption('standard', 'The default code style')
            ->isRequired()
            ->withDefaultValue('PSR12');
        $configOptionsBuilder->describeArrayOption('excluded', 'The excluded files and folders.');
        $configOptionsBuilder->describeArrayOption(
            'custom_flags',
            'Any custom flags to pass to phpcbf. For valid flags refer to the cphpcs documentation.',
        );
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $buildConfig
    ): iterable {
        $projectRoot = $buildConfig->getProjectConfiguration()->getProjectRootPath();
        foreach ($config->getStringList('directories') as $directory) {
            $tmpfile = $buildConfig->getUniqueTempFile($this, 'checkstyle.xml');

            yield $buildConfig
                ->getTaskFactory()
                ->buildRunPhar('phpcs', $this->buildArguments($directory, $config, $tmpfile))
                ->withWorkingDirectory($projectRoot)
                ->withOutputTransformer(CheckstyleReportAppender::transformFile($tmpfile, $projectRoot))
                ->build();
        }
    }

    private function buildArguments(
        string $directory,
        PluginConfigurationInterface $config,
        string $tempFile
    ): array {
        $arguments = [];
        $arguments[] = '--standard=' . $config->getString('standard');

        if ($config->has('excluded')) {
            $arguments[] = '--exclude=' . implode(',', $config->getStringList('excluded'));
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $arguments[] = $value;
            }
        }

        $arguments[] = '--report=checkstyle';
        $arguments[] = '--report-file=' . $tempFile;

        $arguments[] = $directory;

        return $arguments;
    }
};
