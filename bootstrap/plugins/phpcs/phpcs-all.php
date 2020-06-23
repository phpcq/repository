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
        $configOptionsBuilder
            ->describeStringListOption('excluded', 'The excluded files and folders.')
            ->isRequired()
            ->withDefaultValue([]);
        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass to phpcbf. For valid flags refer to the cphpcs documentation.',
            )
            ->isRequired()
            ->withDefaultValue([]);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $projectRoot = $environment->getProjectConfiguration()->getProjectRootPath();
        $tmpfile     = $environment->getUniqueTempFile($this, 'checkstyle.xml');

        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('phpcs', $this->buildArguments($config, $environment, $tmpfile))
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer(CheckstyleReportAppender::transformFile($tmpfile, $projectRoot))
            ->build();
    }

    private function buildArguments(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment,
        string $tempFile
    ): array {
        $arguments = [];
        $arguments[] = '--standard=' . $config->getString('standard');

        if ([] !== ($excluded = $config->getStringList('excluded'))) {
            $arguments[] = '--exclude=' . implode(',', $excluded);
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $arguments[] = $value;
            }
        }

        $arguments[] = '--parallel=' . $environment->getProjectConfiguration()->getMaxCpuCores();
        $arguments[] = '--report=checkstyle';
        $arguments[] = '--report-file=' . $tempFile;

        return array_merge($arguments, $config->getStringList('directories'));
    }
};
