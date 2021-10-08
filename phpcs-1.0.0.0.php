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
            ->withDefaultValue('PSR12');
        $configOptionsBuilder
            ->describeStringListOption('excluded', 'The excluded files and folders.')
            ->isRequired()
            ->withDefaultValue([]);
        $configOptionsBuilder
            ->describeStringListOption(
                'custom_flags',
                'Any custom flags to pass. For valid flags refer to the phpcs documentation.',
            )
            ->isRequired()
            ->withDefaultValue([]);
        $configOptionsBuilder
            ->describeStringListOption(
                'standard_paths',
                'Setting the installed standard paths as relative path to the project root dir.'
            )
            ->isRequired()
            ->withDefaultValue([]);
        $configOptionsBuilder
            ->describeBoolOption(
                'fix',
                'If given, the source will be fixed automatically with phpcbf'
            )
            ->isRequired()
            ->withDefaultValue(false);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $projectRoot = $environment->getProjectConfiguration()->getProjectRootPath();
        $tmpfile     = $environment->getUniqueTempFile($this, 'checkstyle.xml');

        if ($config->getBool('fix')) {
            yield $environment
                ->getTaskFactory()
                ->buildRunPhar('phpcbf', $this->buildArguments($config, $environment, null))
                ->withCosts($environment->getAvailableThreads())
                ->withWorkingDirectory($projectRoot)
                ->build();
        }

        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('phpcs', $this->buildArguments($config, $environment, $tmpfile))
            ->withCosts($environment->getAvailableThreads())
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer(CheckstyleReportAppender::transformFile($tmpfile, $projectRoot))
            ->build();
    }

    /** @return string[] */
    private function buildArguments(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment,
        ?string $tempFile
    ): array {
        $arguments = [];

        if ($config->has('standard')) {
            $arguments[] = '--standard=' . $config->getString('standard');
        }

        if ([] !== ($excluded = $config->getStringList('excluded'))) {
            $arguments[] = '--exclude=' . implode(',', $excluded);
        }

        if ($config->has('custom_flags')) {
            foreach ($config->getStringList('custom_flags') as $value) {
                $arguments[] = $value;
            }
        }

        if ([] !== ($standardPaths = $config->getStringList('standard_paths'))) {
            $projectPath = $environment->getProjectConfiguration()->getProjectRootPath();
            $arguments[] = '--runtime-set';
            $arguments[] = 'installed_paths';
            $arguments[] = implode(',', array_map(
                function ($path) use ($projectPath): string {
                    return realpath($projectPath . '/' . $path);
                },
                $standardPaths
            ));
        }

        $arguments[] = '--parallel=' . $environment->getAvailableThreads();
        if (null !== $tempFile) {
            $arguments[] = '--report=checkstyle';
            $arguments[] = '--report-file=' . $tempFile;
        }

        return array_merge($arguments, $config->getStringList('directories'));
    }
};
