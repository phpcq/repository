<?php

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;

// phpcs:disable PSR12.Files.FileHeader.IncorrectOrder - This is not the file header but psalm annotations
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
        return 'phpcbf';
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
                'Any custom flags to pass to phpcbf. For valid flags refer to the cphpcs documentation.',
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
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        yield $environment
            ->getTaskFactory()
            ->buildRunPhar('phpcbf', $this->buildArguments($config, $environment))
            ->withWorkingDirectory($environment->getProjectConfiguration()->getProjectRootPath())
            ->build();
    }

    /** @return string[] */
    private function buildArguments(PluginConfigurationInterface $config, EnvironmentInterface $environment): array
    {
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

        $arguments[] = '--parallel=' . $environment->getProjectConfiguration()->getMaxCpuCores();

        return array_merge($arguments, $config->getStringList('directories'));
    }
};
